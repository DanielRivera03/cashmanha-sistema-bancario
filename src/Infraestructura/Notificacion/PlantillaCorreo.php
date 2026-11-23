<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Notificacion;

use CashMan\Infraestructura\Config;

/**
 * Plantillas HTML para los correos del sistema.
 *
 * Maquetadas con tablas y estilos en línea a propósito: los clientes de
 * correo —Outlook sobre todo— ignoran hojas de estilo, flexbox y grid.
 * Lo que se ve bien en el navegador se desarma en Outlook si no se
 * monta con tablas.
 *
 * Lo que distingue un correo de banco de uno cualquiera no es el
 * adorno, son cuatro cosas que aquí están deliberadamente:
 *
 *   EL IMPORTE ARRIBA. Quien abre el correo de un cargo quiere saber
 *   cuánto, y lo quiere antes de leer nada. Va en grande, con el
 *   concepto debajo.
 *
 *   LA REFERENCIA SIEMPRE. Es el dato con el que el cliente reclama. Un
 *   correo que avisa de un movimiento y no trae con qué reclamarlo
 *   obliga a llamar para que se lo lean.
 *
 *   EL PREENCABEZADO. Es la línea que el gestor de correo enseña junto
 *   al asunto en la bandeja. Sin ella se cuela ahí el primer texto del
 *   mensaje —normalmente «Banca en línea»—, y todos los correos se ven
 *   iguales en la lista.
 *
 *   USTED, SIEMPRE. El sistema trata de usted al cliente en todas sus
 *   pantallas; los correos alternaban «Usa este código» con «Estimado
 *   señor, puede usted». Leído seguido parecen de dos instituciones
 *   distintas.
 */
final class PlantillaCorreo
{
    /* Morado institucional: el mismo que usa el sistema y los documentos.
       Estuvo un tiempo en verde por un cambio que nadie pidio. */
    private const MARCA   = '#6418c3';
    private const OSCURO  = '#41107f';
    private const TENUE   = '#f4eefc';
    private const TEXTO   = '#1f2933';
    private const SUAVE   = '#667085';
    private const BORDE   = '#e3e8ee';
    private const VERDE   = '#027a48';
    private const ROJO    = '#b42318';
    /* El hilo bajo la banda. Un detalle, pero es lo que separa un
       correo de banco de uno de cualquier formulario. */
    private const ORO     = '#c99a2e';

    /**
     * El armazón de todos los correos.
     *
     * @param array<int,array{etiqueta:string,valor:string}> $datos
     * @param array{importe?:string,concepto?:string,signo?:string} $cifra
     *        El importe que encabeza el mensaje. `signo` admite '+',
     *        '-' o '' y solo cambia el color.
     * @param array<int,string> $adjuntos Nombres de los archivos que van
     *        con el mensaje, para anunciarlos en el cuerpo.
     */
    public static function base(
        string $titulo,
        string $saludo,
        string $cuerpo,
        array $datos = [],
        ?string $destacado = null,
        ?string $botonTexto = null,
        ?string $botonUrl = null,
        string $aviso = '',
        string $preencabezado = '',
        array $cifra = [],
        array $adjuntos = [],
        /* Cuál de los iconos acompaña al título: exito, aviso, error,
           info o movimiento. Vacío para no poner ninguno. Son PNG
           incrustados porque en correo no hay otra cosa que se vea:
           Gmail quita el SVG y las fuentes de iconos no se cargan. */
        string $icono = ''
    ): string {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $app = Config::obtener('APP_NOMBRE', 'CashMan H.A.');

        // --- El importe que encabeza ------------------------------------
        $bloqueCifra = '';

        if (($cifra['importe'] ?? '') !== '') {
            $signo = (string) ($cifra['signo'] ?? '');
            $color = $signo === '-' ? self::ROJO : ($signo === '+' ? self::VERDE : self::TEXTO);

            $bloqueCifra =
                '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                . ' style="margin:0 0 24px;border-collapse:collapse">'
                . '<tr><td style="background:' . self::TENUE . ';border-radius:12px;'
                . 'padding:22px 24px;text-align:center">'
                . '<div style="font-size:34px;line-height:1.1;font-weight:700;color:' . $color . ';'
                . 'letter-spacing:-.02em">' . $e($signo . ($cifra['importe'] ?? '')) . '</div>'
                . (($cifra['concepto'] ?? '') !== ''
                    ? '<div style="margin-top:6px;font-size:13px;color:' . self::SUAVE . '">'
                      . $e((string) $cifra['concepto']) . '</div>'
                    : '')
                . '</td></tr></table>';
        }

        /* El icono del título. Solo si se pidió uno de los que existen:
           un `cid:` sin imagen detrás deja un recuadro roto, que es
           peor que no poner nada. */
        $bloqueIcono = '';

        if (in_array($icono, ['exito', 'aviso', 'error', 'info', 'movimiento'], true)) {
            $bloqueIcono = '<td valign="middle" style="padding-right:11px;width:30px">'
                . '<img src="cid:icono-' . $icono . '" alt="" width="30" height="30"'
                . ' style="display:block;border:0;outline:none;width:30px;height:30px">'
                . '</td>';
        }

        // --- La ficha de datos -------------------------------------------
        $filas = '';

        foreach ($datos as $d) {
            $filas .= '<tr>'
                . '<td style="padding:10px 0;border-bottom:1px solid ' . self::BORDE . ';'
                . 'color:' . self::SUAVE . ';font-size:13px;vertical-align:top">'
                . $e($d['etiqueta']) . '</td>'
                . '<td style="padding:10px 0;border-bottom:1px solid ' . self::BORDE . ';'
                . 'text-align:right;font-weight:600;color:' . self::TEXTO . ';font-size:13px;'
                . 'vertical-align:top">' . $e($d['valor']) . '</td>'
                . '</tr>';
        }

        $bloqueDatos = $filas === '' ? '' :
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                    style="margin:4px 0 22px;border-collapse:collapse">' . $filas . '</table>';

        // --- El código, cuando lo hay -------------------------------------
        $bloqueDestacado = $destacado === null ? '' :
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
            . ' style="margin:22px 0;border-collapse:collapse">'
            . '<tr><td style="background:' . self::TENUE . ';border-left:4px solid ' . self::MARCA . ';'
            . 'padding:18px 22px;border-radius:0 10px 10px 0">'
            . '<div style="font-size:31px;font-weight:700;color:' . self::OSCURO . ';'
            . 'letter-spacing:.14em;font-family:Consolas,Monaco,monospace">'
            . $e($destacado) . '</div></td></tr></table>';

        $bloqueBoton = ($botonTexto === null || $botonUrl === null) ? '' :
            '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0">'
            . '<tr><td style="border-radius:9px;background:' . self::MARCA . '">'
            . '<a href="' . $e($botonUrl) . '" style="display:inline-block;padding:13px 30px;'
            . 'color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;border-radius:9px">'
            . $e($botonTexto) . '</a></td></tr></table>';

        $bloqueAviso = $aviso === '' ? '' :
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
            . ' style="margin:22px 0;border-collapse:collapse">'
            . '<tr><td style="background:#fff8e6;border:1px solid #f5d98b;border-radius:10px;'
            . 'padding:14px 17px;font-size:13px;line-height:1.55;color:#8a6116">'
            . $e($aviso) . '</td></tr></table>';

        /* Los adjuntos, anunciados.
           Un PDF que llega sin que el cuerpo lo mencione pasa
           desapercibido, y el cliente escribe preguntando por el
           comprobante que ya tenía delante. */
        $bloqueAdjuntos = $adjuntos === [] ? '' :
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
            . ' style="margin:22px 0;border-collapse:collapse">'
            . '<tr><td style="background:#f8fafc;border:1px solid ' . self::BORDE . ';'
            . 'border-radius:10px;padding:14px 17px">'
            . '<div style="font-size:13px;font-weight:600;color:' . self::TEXTO . ';margin-bottom:6px">'
            . (count($adjuntos) === 1 ? 'Documento adjunto' : 'Documentos adjuntos') . '</div>'
            . '<div style="font-size:12px;color:' . self::SUAVE . ';line-height:1.6">'
            . implode('<br>', array_map(
                static fn(string $n): string => '&bull; ' . $e($n), $adjuntos
            ))
            . '</div></td></tr></table>';

        /* El preencabezado: la línea de la bandeja de entrada.
           Va oculto y seguido de espacios invisibles para que el
           gestor no arrastre detrás el principio del mensaje. */
        $oculto = $preencabezado === '' ? '' :
            '<div style="display:none;max-height:0;overflow:hidden;opacity:0;'
            . 'mso-hide:all;font-size:1px;line-height:1px;color:#ffffff">'
            . $e($preencabezado) . str_repeat('&#8199;&#65279;&#847;', 30) . '</div>';

        $anio = date('Y');

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{$e($titulo)}</title>
</head>
<body style="margin:0;padding:0;background:#eef1f5;-webkit-font-smoothing:antialiased;
             font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">

{$oculto}

<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="background:#eef1f5;padding:30px 12px">
<tr><td align="center">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
         style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;
                box-shadow:0 2px 10px rgba(16,24,40,.08)">

    <!-- Cabecera -->
    <tr><td style="background:{$e(self::OSCURO)};
                   background-image:linear-gradient(135deg,{$e(self::OSCURO)} 0%,{$e(self::MARCA)} 100%);
                   padding:26px 32px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
        <td valign="middle">
          <!-- El isotipo (no el wordmark) viaja DENTRO del mensaje
               (cid), no enlazado: Gmail y Outlook bloquean las
               imágenes remotas y aquí quedaría un recuadro vacío donde
               va la marca. El `alt` cubre el caso de que ni así se vea.
               Lockup en tabla anidada —no inline-block— porque Outlook
               lo desarma. -->
          <table role="presentation" cellpadding="0" cellspacing="0"><tr>
            <td valign="middle" style="padding-right:9px">
              <img src="cid:emblema" alt="{$e($app)}" width="48" height="30"
                   style="display:block;border:0;outline:none;width:48px;height:30px">
            </td>
            <td valign="middle" style="color:#ffffff;font-size:15px;font-weight:700;
                                       letter-spacing:.01em;white-space:nowrap">
              {$e($app)}
            </td>
          </tr></table>
        </td>
        <td align="right" valign="middle"
            style="color:#e2d4fa;font-size:10px;letter-spacing:.14em;
                   text-transform:uppercase;font-weight:600">
          Banca en línea
        </td>
      </tr></table>
    </td></tr>

    <!-- Hilo de marca: separa la banda del contenido sin pesar. -->
    <tr><td style="height:3px;line-height:3px;font-size:0;
                   background:{$e(self::ORO)}">&nbsp;</td></tr>

    <!-- Contenido -->
    <tr><td style="padding:30px 32px 26px">
      <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 4px">
        <tr>
          {$bloqueIcono}
          <td valign="middle">
            <h1 style="margin:0;font-size:21px;line-height:1.3;color:{$e(self::TEXTO)};
                       font-weight:700;letter-spacing:-.01em">
              {$e($titulo)}
            </h1>
          </td>
        </tr>
      </table>
      <p style="margin:0 0 20px;color:{$e(self::SUAVE)};font-size:14px">
        {$e($saludo)}
      </p>

      {$bloqueCifra}

      <div style="color:{$e(self::TEXTO)};font-size:15px;line-height:1.62">
        {$cuerpo}
      </div>

      {$bloqueDestacado}
      {$bloqueDatos}
      {$bloqueBoton}
      {$bloqueAdjuntos}
      {$bloqueAviso}
    </td></tr>

    <!-- Atención al cliente -->
    <tr><td style="padding:0 32px 26px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
             style="border-top:1px solid {$e(self::BORDE)};border-collapse:collapse">
        <tr><td style="padding-top:18px;font-size:12px;line-height:1.65;
                       color:{$e(self::SUAVE)}">
          <strong style="color:{$e(self::TEXTO)}">¿Necesita ayuda?</strong><br>
          Atención al cliente, de lunes a viernes de 8:00 a 17:00 y sábados de 8:00 a 12:00.<br>
          Desde su banca en línea, en la sección Soporte, queda constancia de su consulta.
        </td></tr>
      </table>
    </td></tr>

    <!-- Pie -->
    <tr><td style="background:#f8fafc;padding:20px 32px;border-top:1px solid {$e(self::BORDE)}">
      <p style="margin:0 0 7px;font-size:11px;color:{$e(self::SUAVE)};line-height:1.6">
        Este mensaje se generó automáticamente; por favor no responda a esta dirección.
        Si no reconoce esta actividad, comuníquese de inmediato con atención al cliente.
      </p>
      <p style="margin:0 0 7px;font-size:11px;color:#98a2b3;line-height:1.6">
        {$e($app)} El Salvador, S.A. de C.V. &middot; San Salvador, El Salvador &middot;
        Entidad sujeta a la Ley de Bancos y a la supervisión de la Superintendencia
        del Sistema Financiero.
      </p>
      <p style="margin:0 0 10px;font-size:11px;color:#98a2b3">
        &copy; {$e($anio)} {$e($app)} &middot; Todos los derechos reservados
      </p>
      <!-- La marca también al cierre, en pequeño: es lo último que
           queda a la vista al archivar el correo. -->
      <img src="cid:emblema" alt="" height="18"
           style="display:block;border:0;outline:none;height:18px;width:auto;
                  max-width:110px;opacity:.45">
    </td></tr>

  </table>

</td></tr>
</table>

</body>
</html>
HTML;
    }

    public static function codigoSeguridad(
        string $nombre,
        string $codigo,
        string $operacion,
        int $minutos
    ): string {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return self::base(
            'Código de verificación',
            'Estimado(a) ' . $nombre . ',',
            '<p style="margin:0 0 10px">Use este código para confirmar la siguiente operación:</p>'
            . '<p style="margin:0;color:' . self::SUAVE . '"><strong style="color:'
            . self::TEXTO . '">' . $e($operacion) . '</strong></p>',
            [],
            $codigo,
            null,
            null,
            'El código vence en ' . $minutos . ' minutos y sirve una sola vez. '
            . 'Nadie de CashMan se lo pedirá por teléfono, por mensaje ni por correo. '
            . 'Si usted no solicitó esta operación, no lo use y comuníquese con nosotros.',
            'Su código vence en ' . $minutos . ' minutos'
        );
    }

    /**
     * El acuse de una operación.
     *
     * @param array<int,array{etiqueta:string,valor:string}> $datos
     * @param array{importe?:string,concepto?:string,signo?:string} $cifra
     * @param array<int,string> $adjuntos
     */
    public static function comprobante(
        string $nombre,
        string $titulo,
        string $resumen,
        array $datos,
        ?string $url = null,
        array $cifra = [],
        array $adjuntos = [],
        string $aviso = '',
        /* La severidad del aviso, que ya venía decidida: sirve tal cual
           para elegir el icono y no hay que tocar los sitios que crean
           notificaciones. */
        string $icono = 'movimiento'
    ): string {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return self::base(
            $titulo,
            'Estimado(a) ' . $nombre . ',',
            '<p style="margin:0">' . $e($resumen) . '</p>',
            $datos,
            null,
            $url !== null ? 'Ver el detalle en mi banca' : null,
            $url,
            $aviso,
            // En la bandeja de entrada se lee el importe sin abrir nada.
            ($cifra['importe'] ?? '') !== ''
                ? trim(($cifra['signo'] ?? '') . $cifra['importe'] . ' · ' . $resumen)
                : $resumen,
            $cifra,
            $adjuntos,
            $icono
        );
    }

    public static function bienvenida(string $nombre, string $usuario, string $url): string
    {
        return self::base(
            'Su cuenta ya está lista',
            'Estimado(a) ' . $nombre . ',',
            '<p style="margin:0 0 12px">Ya puede ingresar a su banca en línea. Por seguridad, '
            . 'la primera vez que entre le pediremos cambiar su contraseña.</p>'
            . '<p style="margin:0">Desde ahí podrá consultar sus cuentas y tarjetas, pagar '
            . 'sus cuotas, descargar sus estados de cuenta y conservar el comprobante de '
            . 'cada operación.</p>',
            [['etiqueta' => 'Usuario', 'valor' => $usuario]],
            null,
            'Ingresar a mi banca',
            $url,
            'Nunca comparta su contraseña. CashMan jamás se la pedirá por correo ni por '
            . 'teléfono.',
            'Su usuario es ' . $usuario
        );
    }

    /**
     * Cambio de escalón en la banca preferencial.
     *
     * Se usa en los dos sentidos. Un ascenso se anuncia; un descenso
     * también, y con el mismo cuidado: el cliente va a notar que una
     * condición que tenía ya no está, y es mejor que lo sepa por
     * nosotros y con el motivo delante que descubriéndolo solo.
     *
     * Los beneficios NO se escriben aquí: llegan ya resueltos desde
     * `Segmento::beneficiosVisibles()`, que es la misma fuente que lee
     * la pantalla del cliente y la que aplican los servicios. Tener
     * una segunda lista escrita en un correo es la forma más segura de
     * acabar prometiendo por escrito algo que el sistema no hace.
     *
     * @param list<array{titulo:string, detalle:string}> $beneficios
     * @param list<string> $motivos Por qué le corresponde este escalón.
     */
    public static function cambioSegmento(
        string $nombre,
        string $segmentoNuevo,
        string $segmentoAnterior,
        array $beneficios,
        array $motivos,
        string $url,
        bool $asciende = true
    ): string {
        $e = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        /* La lista de beneficios, cada uno con su explicación. Un
           listado de titulares sin explicar para qué sirven obliga al
           cliente a adivinar qué gana, que es justo lo que este correo
           tiene que evitar. */
        $lista = '';

        foreach ($beneficios as $b) {
            $lista .=
                '<tr><td style="padding:0 0 16px;vertical-align:top;width:26px">'
                . '<div style="width:18px;height:18px;border-radius:50%;background:'
                . self::TENUE . ';color:' . self::MARCA . ';font-size:12px;line-height:18px;'
                . 'text-align:center;font-weight:700">&#10003;</div></td>'
                . '<td style="padding:0 0 16px">'
                . '<div style="font-size:14px;font-weight:600;color:' . self::TEXTO . ';'
                . 'line-height:1.4">' . $e($b['titulo']) . '</div>'
                . '<div style="font-size:13px;color:' . self::SUAVE . ';line-height:1.55;'
                . 'margin-top:3px">' . $e($b['detalle']) . '</div>'
                . '</td></tr>';
        }

        $bloqueBeneficios = $lista === '' ? '' :
            '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;'
            . 'text-transform:uppercase;color:' . self::SUAVE . ';margin:26px 0 14px">'
            . ($asciende ? 'Lo que incluye su nueva categoría' : 'Lo que mantiene')
            . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
            . ' style="border-collapse:collapse">' . $lista . '</table>';

        /* Por qué le corresponde. Un cambio de categoría sin motivo
           parece arbitrario, y si el cliente no sabe qué lo produjo
           tampoco sabe qué hacer para conservarlo. */
        $bloqueMotivos = '';

        if ($motivos !== []) {
            $items = '';

            foreach ($motivos as $m) {
                $items .= '<li style="margin:0 0 6px">' . $e($m) . '</li>';
            }

            $bloqueMotivos =
                '<div style="background:' . self::TENUE . ';border-radius:12px;'
                . 'padding:18px 20px;margin:24px 0 0">'
                . '<div style="font-size:13px;font-weight:700;color:' . self::OSCURO . ';'
                . 'margin:0 0 8px">Por qué le corresponde</div>'
                . '<ul style="margin:0;padding-left:18px;font-size:13px;color:' . self::SUAVE . ';'
                . 'line-height:1.6">' . $items . '</ul>'
                . '</div>';
        }

        $cuerpo = $asciende
            ? '<p style="margin:0 0 12px">Nos complace informarle que su relación con '
              . 'CashMan H.A. pasa a la categoría <strong style="color:' . self::OSCURO . '">'
              . $e($segmentoNuevo) . '</strong>.</p>'
              . '<p style="margin:0 0 12px">El cambio ya está aplicado. No tiene que '
              . 'solicitarlo, firmar nada ni esperar: las condiciones que verá a '
              . 'continuación rigen desde hoy, tanto en los productos que contrate '
              . 'como en los que ya tiene con nosotros.</p>'
            : '<p style="margin:0 0 12px">Le informamos que su categoría pasa de '
              . '<strong>' . $e($segmentoAnterior) . '</strong> a '
              . '<strong style="color:' . self::OSCURO . '">' . $e($segmentoNuevo) . '</strong>.</p>'
              . '<p style="margin:0 0 12px">La categoría se revisa de forma automática '
              . 'con una regla única para todos los clientes; nadie la asigna ni la '
              . 'retira a mano. Si su situación vuelve a cumplir las condiciones, el '
              . 'escalón se restituye solo, sin que tenga que pedirlo.</p>';

        return self::base(
            $asciende
                ? 'Bienvenido a Banca ' . $segmentoNuevo
                : 'Cambio en su categoría',
            'Estimado(a) ' . $nombre . ',',
            $cuerpo . $bloqueBeneficios . $bloqueMotivos,
            [
                ['etiqueta' => 'Categoría anterior', 'valor' => $segmentoAnterior],
                ['etiqueta' => 'Categoría actual',   'valor' => $segmentoNuevo],
                ['etiqueta' => 'Vigente desde',      'valor' => date('d/m/Y')],
            ],
            null,
            'Ver mis condiciones',
            $url,
            'La categoría se revisa periódicamente según sus saldos, su línea, su '
            . 'ingreso registrado, su antigüedad y su comportamiento de pago. Puede '
            . 'consultar en cualquier momento qué condiciones tiene y qué se toma en '
            . 'cuenta desde su banca en línea.',
            $asciende
                ? 'Su categoría cambia a ' . $segmentoNuevo
                : 'Actualización de su categoría'
        );
    }
    public static function alertaSeguridad(
        string $nombre,
        string $evento,
        string $ip,
        string $cuando
    ): string {
        return self::base(
            'Actividad en su cuenta',
            'Estimado(a) ' . $nombre . ',',
            '<p style="margin:0">Le informamos de la siguiente actividad registrada en su '
            . 'cuenta. Si fue usted, no tiene que hacer nada.</p>',
            [
                ['etiqueta' => 'Evento', 'valor' => $evento],
                ['etiqueta' => 'Dirección IP', 'valor' => $ip],
                ['etiqueta' => 'Fecha y hora', 'valor' => $cuando],
            ],
            null,
            null,
            null,
            'Si no fue usted, cambie su contraseña de inmediato y comuníquese con nosotros.',
            $evento . ' · ' . $cuando
        );
    }
}
