<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Documento;

use CashMan\Infraestructura\Config;

/**
 * Generador de documentos PDF sobre FPDF.
 *
 * FPDF trabaja en ISO-8859-1, así que todo texto se convierte antes de
 * escribirlo: sin eso los acentos y la ñ salen como símbolos raros,
 * que es como se veían los PDF del sistema anterior.
 *
 * Los documentos se guardan en storage/pdf/, FUERA del webroot, y se
 * sirven por PHP previa verificación de permiso. Cada uno lleva su
 * hash SHA-256 impreso: si alguien edita el archivo, el hash deja de
 * coincidir con el registrado en la base.
 */
class GeneradorPdf extends \FPDF
{
    protected string $tituloDoc = '';
    protected string $subtitulo = '';
    protected string $folio     = '';
    protected bool   $conPie    = true;

    /**
     * Paleta institucional, en RGB.
     *
     * El morado #6418c3 es el color de la marca: es el que domina el
     * sistema desde su primera versión. Los documentos estuvieron un
     * tiempo en verde por un cambio que nadie pidió; esto los devuelve
     * a la identidad real.
     */
    protected const MARCA        = [100, 24, 195];   // #6418c3
    protected const MARCA_OSCURA = [65, 16, 127];    // #41107f
    protected const MARCA_TENUE  = [244, 238, 252];  // #f4eefc
    /* Tinta de los textos. El morado es la marca, no la letra: un
       documento con los títulos en color compite con las cifras y se
       lee peor, sobre todo impreso. */
    protected const TINTA        = [32, 38, 52];
    protected const GRIS         = [102, 112, 133];
    protected const BORDE        = [227, 232, 238];

    /**
     * Logo aplanado sobre blanco.
     *
     * FPDF no admite PNG con canal alfa, y el logo original lo tiene, de
     * modo que se usa una copia sin transparencia generada aparte. Si el
     * archivo falta, el encabezado se dibuja sin él en lugar de romper
     * el documento: un estado de cuenta sin logo sigue sirviendo.
     */
    protected const LOGO = __DIR__ . '/../../../public/assets/pdf/logo-pdf.png';

    /**
     * @param string|array{0:float,1:float} $formato Nombre ('Letter', 'A4')
     *                                               o medidas [ancho, alto]
     */
    public function __construct(string $orientacion = 'P', string $unidad = 'mm', string|array $formato = 'Letter')
    {
        parent::__construct($orientacion, $unidad, $formato);
        $this->SetAutoPageBreak(true, 22);
        $this->SetMargins(14, 14, 14);
        $this->AliasNbPages();
    }

    /**
     * Celda que encoge la letra en vez de desbordarse.
     *
     * `Cell()` no recorta ni parte: lo que no cabe se dibuja fuera y se
     * monta sobre lo siguiente. En hojas de 216 mm nunca molestó, pero
     * el comprobante usa 140 mm y ahí el título del documento y el
     * domicilio se salían.
     *
     * Se baja el cuerpo de letra hasta que entre, con un suelo para que
     * no acabe ilegible; si ni así cabe, entonces sí se recorta.
     */
    protected function celdaEncogida(
        float $w,
        float $h,
        string $texto,
        string $align = 'L',
        int $ln = 0,
        float $tamano = 9.0,
        float $minimo = 6.0
    ): void {
        $familia = $this->FontFamily;
        $estilo  = $this->FontStyle;
        $t       = $this->txt($texto);

        $usar = $tamano;

        while ($usar > $minimo && $this->GetStringWidth($t) + 2.0 > $w) {
            $usar = round($usar - 0.2, 1);
            $this->SetFont($familia, $estilo, $usar);
        }

        $this->Cell($w, $h, $this->ajustar($texto, $w), 0, $ln, $align);
        $this->SetFont($familia, $estilo, $tamano);
    }
    public function configurar(string $titulo, string $subtitulo = '', string $folio = ''): void
    {
        $this->tituloDoc = $titulo;
        $this->subtitulo = $subtitulo;
        $this->folio     = $folio;

        $this->SetTitle($this->txt($titulo), true);
        $this->SetAuthor($this->txt(Config::obtener('APP_NOMBRE', 'CashMan H.A.')), true);
        $this->SetCreator('CashMan H.A.');
    }

    /** Convierte UTF-8 a la codificación que entiende FPDF. */
    protected function txt(string $s): string
    {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;
    }

    // -----------------------------------------------------------------
    // Cabecera y pie
    // -----------------------------------------------------------------

    public function Header(): void
    {
        $ancho  = $this->GetPageWidth();
        $margen = 14.0;

        // Franja de marca al borde superior. Es lo que identifica el
        // documento de un vistazo, incluso doblado o fotocopiado.
        [$r, $g, $b] = self::MARCA;
        $this->SetFillColor($r, $g, $b);
        $this->Rect(0, 0, $ancho, 3.2, 'F');

        // El resto de la cabecera va sobre blanco: un banco imprime sus
        // estados de cuenta, y 26 mm de tinta sólida en cada página es
        // gasto de tóner sin ninguna función.
        $yTexto = 9.0;
        $xTexto = $margen;

        if (is_file(self::LOGO)) {
            $altoLogo = 10.5;
            $this->Image(self::LOGO, $margen, $yTexto - 1, 0, $altoLogo);
            // 289x180 px: la anchura se deduce de la proporción.
            $xTexto = $margen + ($altoLogo * 289 / 180) + 4;
        }

        /* El reparto del ancho se calcula, no se fija a ojo.
           Con `Cell(90, ...)` a la izquierda y una celda derecha a
           continuación, un folio largo se montaba encima del domicilio:
           en el comprobante se leía "San Salvador, EbaDEPo261106-610E…".
           Ahora cada bloque recibe el ancho que le toca y el de la
           izquierda se recorta si hiciera falta. */
        $util      = $ancho - $margen - $xTexto;
        $anchoDer  = min(72.0, $util * 0.48);
        $anchoIzq  = max(30.0, $util - $anchoDer - 3.0);

        $this->SetXY($xTexto, $yTexto);
        $this->SetFont('Helvetica', 'B', 13);
        // También en tinta. El morado queda únicamente en la franja
        // superior, el hilo de separación y el fondo de las cabeceras
        // de tabla: ningún texto del documento va en color.
        [$r, $g, $b] = self::TINTA;
        $this->SetTextColor($r, $g, $b);
        $this->celdaEncogida(
            $anchoIzq, 6, Config::obtener('APP_NOMBRE', 'CashMan H.A.'), 'L', 0, 13.0, 8.5
        );

        // Qué documento es. En gris oscuro y no en morado: el color de
        // marca se reserva para las piezas gráficas —la franja, el hilo,
        // la cabecera de tabla— y no para el texto, que se lee mejor en
        // tinta neutra y no compite con los importes.
        $this->SetFont('Helvetica', 'B', 9.5);
        [$r, $g, $b] = self::TINTA;
        $this->SetTextColor($r, $g, $b);
        $this->celdaEncogida(
            $anchoDer + 3.0, 6, mb_strtoupper($this->tituloDoc), 'R', 1, 9.5, 6.4
        );

        $this->SetX($xTexto);
        $this->SetFont('Helvetica', '', 7.4);
        [$r, $g, $b] = self::GRIS;
        $this->SetTextColor($r, $g, $b);
        $this->celdaEncogida(
            $anchoIzq, 4, 'S.A. de C.V.  ·  San Salvador, El Salvador', 'L', 0, 7.4, 5.6
        );

        // El folio va en su propia línea cuando es largo: partirlo o
        // encimarlo lo vuelve ilegible, y es el dato con el que el
        // cliente reclama.
        $derecha = $this->folio !== ''
            ? 'Folio ' . $this->folio
            : date('d/m/Y H:i');

        $this->SetFont('Helvetica', '', 7.0);
        $this->Cell($anchoDer + 3.0, 4, $this->txt($derecha), 0, 1, 'R');

        if ($this->folio !== '') {
            $this->SetX($xTexto + $anchoIzq);
            $this->Cell($anchoDer + 3.0, 3.6, $this->txt(date('d/m/Y H:i')), 0, 1, 'R');
        }

        if ($this->subtitulo !== '') {
            $this->SetX($xTexto);
            $this->SetFont('Helvetica', '', 7.4);
            $this->celdaEncogida($anchoIzq, 4, $this->subtitulo, 'L', 1, 7.4, 5.6);
        }

        /* Hilo de separación, debajo de donde termine la cabecera.
           Estaba clavado en y=24, pero con folio y subtítulo el último
           renglón ocupa de 22.6 a 26.6: la línea le cruzaba el texto
           por la mitad —se veía en «Crédito Personal» del finiquito—.
           Ahora se pregunta dónde acabó de escribir en vez de suponerlo,
           con un mínimo para que la cabecera corta no quede apretada. */
        $y = max(24.0, $this->GetY() + 2.4);

        [$r, $g, $b] = self::MARCA;
        $this->SetDrawColor($r, $g, $b);
        $this->SetLineWidth(0.5);
        $this->Line($margen, $y, $ancho - $margen, $y);
        $this->SetLineWidth(0.2);

        // El cuerpo arranca bajo el hilo, no en una altura fija: si la
        // cabecera crece, el contenido baja con ella.
        $this->SetY($y + 6.0);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer(): void
    {
        if (!$this->conPie) {
            return;
        }

        $this->SetY(-18);

        [$r, $g, $b] = self::BORDE;
        $this->SetDrawColor($r, $g, $b);
        $this->Line(14, $this->GetY(), $this->GetPageWidth() - 14, $this->GetY());

        $this->SetY(-15);
        $this->SetFont('Helvetica', '', 7);
        [$r, $g, $b] = self::GRIS;
        $this->SetTextColor($r, $g, $b);

        $this->Cell(120, 4, $this->txt(
            'Documento generado el ' . date('d/m/Y H:i') . ' - No requiere firma'
        ), 0, 0, 'L');

        $this->Cell(0, 4, $this->txt('Pagina ' . $this->PageNo() . ' de {nb}'), 0, 1, 'R');

        /* Identificación de la institución.
         *
         * Un documento que sale del banco y circula impreso tiene que
         * decir de quién es, no solo llevar el logo arriba.
         *
         * Aquí había un `Cell` centrado con un comentario que afirmaba
         * que el texto «se acorta si no cabe». No se acortaba: `Cell`
         * no recorta ni ajusta nada, así que la línea, más ancha que el
         * papel, se desbordaba por los dos lados a la vez y en pantalla
         * se leía «…El Salvador, S.A. de C.V.» por la izquierda y
         * «…Superintendencia del Sister» cortado por la derecha.
         *
         * Ahora se mide de verdad: se reduce el cuerpo de letra hasta
         * que quepa y, si aun así no cabe, se parte en dos líneas. Un
         * pie legal ilegible no cumple su función. */
        $ancho = $this->GetPageWidth() - 28;

        $legal = 'CashMan H.A. El Salvador, S.A. de C.V.  ·  San Salvador, El Salvador  ·  '
               . 'Entidad sujeta a la Ley de Bancos y a la supervision de la '
               . 'Superintendencia del Sistema Financiero.';

        $texto = $this->txt($legal);
        $cuerpo = 6.2;

        $this->SetFont('Helvetica', '', $cuerpo);

        while ($cuerpo > 4.8 && $this->GetStringWidth($texto) > $ancho) {
            $cuerpo -= 0.2;
            $this->SetFont('Helvetica', '', $cuerpo);
        }

        $this->SetX(14);

        if ($this->GetStringWidth($texto) <= $ancho) {
            $this->Cell($ancho, 3.4, $texto, 0, 0, 'C');
            return;
        }

        // No cabe ni en el cuerpo mínimo: se reparte en dos renglones por
        // el separador, que es donde la frase admite el corte.
        $partes = explode('  ·  ', $legal);
        $corte  = (int) ceil(count($partes) / 2);

        $this->SetY(-12.5);
        $this->SetX(14);
        $this->Cell($ancho, 3.2, $this->txt(implode('  ·  ', array_slice($partes, 0, $corte))), 0, 1, 'C');
        $this->SetX(14);
        $this->Cell($ancho, 3.2, $this->txt(implode('  ·  ', array_slice($partes, $corte))), 0, 0, 'C');
    }

    // -----------------------------------------------------------------
    // Bloques reutilizables
    // -----------------------------------------------------------------

    public function tituloSeccion(string $texto): void
    {
        /* Un título solo al pie de la hoja no es un título: anuncia
           algo que está en la página siguiente. Si no queda sitio para
           él y para un par de renglones de lo que encabeza, se pasa
           con su contenido. */
        if ($this->GetY() + 18 > $this->GetPageHeight() - 24) {
            $this->AddPage();
        }

        $this->Ln(3);
        $this->SetFont('Helvetica', 'B', 10);
        // En tinta, no en morado: el color queda en el subrayado.
        [$r, $g, $b] = self::TINTA;
        $this->SetTextColor($r, $g, $b);
        $this->Cell(0, 6, $this->txt(mb_strtoupper($texto)), 0, 1, 'L');

        [$r, $g, $b] = self::MARCA;
        $this->SetDrawColor($r, $g, $b);
        $this->SetLineWidth(0.4);
        $this->Line(14, $this->GetY(), 44, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->Ln(2.5);
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Dos columnas de etiqueta/valor.
     *
     * @param array<string,string> $datos
     */
    public function bloqueDatos(array $datos, int $columnas = 2): void
    {
        $ancho = ($this->GetPageWidth() - 28) / $columnas;
        $i     = 0;

        /* El bloque entero o nada.
         *
         * El salto automático parte por renglones, y aquí un renglón
         * son dos cosas distintas: la etiqueta y su valor se escriben
         * sin salto entre ellos, así que al caer el corte en medio
         * quedaba «Tasa nominal anual» al pie de una página y «44.00%»
         * arriba de la siguiente, sin nada que dijera de qué era. */
        $alto = (int) ceil(count($datos) / max(1, $columnas)) * 5 + 6;

        if ($this->GetY() + $alto > $this->GetPageHeight() - 24) {
            $this->AddPage();
        }

        foreach ($datos as $etiqueta => $valor) {
            if ($i > 0 && $i % $columnas === 0) {
                $this->Ln();
            }

            $x = 14 + ($i % $columnas) * $ancho;
            $this->SetX($x);

            $this->SetFont('Helvetica', '', 7.5);
            [$r, $g, $b] = self::GRIS;
            $this->SetTextColor($r, $g, $b);
            $this->Cell($ancho * 0.42, 5, $this->txt($etiqueta), 0, 0, 'L');

            /* El valor, encogido si hace falta.
             *
             * `Cell` no recorta ni ajusta: el texto que no cabe se sale
             * y se imprime encima de la columna de al lado. Por eso en
             * el contrato la direccion «Col La Sultana Cl Las Rosas No
             * 37 Antg Cusc, Tonacatepeque» aparecia montada sobre el
             * cargo del deudor.
             *
             * Primero se prueba a reducir el cuerpo —un dato de un
             * contrato se prefiere pequeño antes que cortado— y solo si
             * ni al minimo entra se recorta con puntos suspensivos. */
            $anchoValor = $ancho * 0.58;
            $texto      = $this->txt($valor);
            $cuerpo     = 8.5;

            $this->SetFont('Helvetica', 'B', $cuerpo);

            while ($cuerpo > 6.0 && $this->GetStringWidth($texto) > $anchoValor - 2.0) {
                $cuerpo -= 0.5;
                $this->SetFont('Helvetica', 'B', $cuerpo);
            }

            $this->SetTextColor(0, 0, 0);
            $this->Cell($anchoValor, 5, $this->ajustar($valor, $anchoValor), 0, 0, 'L');

            $i++;
        }
        $this->Ln(6);
    }

    /**
     * Un dato que ocupa el ancho completo.
     *
     * Para los valores largos —una dirección, el destino de un crédito,
     * la razón social de una empresa—. En media columna no caben, y la
     * alternativa era recortarlos: en un contrato, un dato recortado con
     * puntos suspensivos no sirve para notificar a nadie.
     */
    public function datoAncho(string $etiqueta, string $valor): void
    {
        $ancho = $this->GetPageWidth() - 28;

        $this->SetX(14);

        $this->SetFont('Helvetica', '', 7.5);
        [$r, $g, $b] = self::GRIS;
        $this->SetTextColor($r, $g, $b);
        $this->Cell($ancho * 0.21, 5, $this->txt($etiqueta), 0, 0, 'L');

        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetTextColor(0, 0, 0);

        // MultiCell sí parte el texto en varias líneas cuando no cabe.
        $this->MultiCell($ancho * 0.79, 5, $this->txt($valor), 0, 'L');
    }

    /**
     * Tabla con cabecera de color.
     *
     * @param array<int,string>              $cabeceras
     * @param array<int,array<int,string>>   $filas
     * @param array<int,float>               $anchos   En milímetros
     * @param array<int,string>              $alineacion  L, C o R
     */
    public function tabla(array $cabeceras, array $filas, array $anchos, array $alineacion = []): void
    {
        [$r, $g, $b] = self::MARCA;
        $this->SetFillColor($r, $g, $b);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 7.5);

        foreach ($cabeceras as $i => $c) {
            $this->Cell($anchos[$i] ?? 25, 7, $this->txt($c), 0, 0, $alineacion[$i] ?? 'L', true);
        }
        $this->Ln();

        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(0, 0, 0);
        [$br, $bg, $bb] = self::BORDE;
        $this->SetDrawColor($br, $bg, $bb);

        $alterna = false;
        foreach ($filas as $fila) {
            // Salto de página manteniendo la cabecera.
            if ($this->GetY() > $this->GetPageHeight() - 30) {
                $this->AddPage();
                $this->SetFillColor($r, $g, $b);
                $this->SetTextColor(255, 255, 255);
                $this->SetFont('Helvetica', 'B', 7.5);
                foreach ($cabeceras as $i => $c) {
                    $this->Cell($anchos[$i] ?? 25, 7, $this->txt($c), 0, 0, $alineacion[$i] ?? 'L', true);
                }
                $this->Ln();
                $this->SetFont('Helvetica', '', 7.5);
                $this->SetTextColor(0, 0, 0);
            }

            [$tr, $tg, $tb] = self::MARCA_TENUE;
            $this->SetFillColor($tr, $tg, $tb);
            foreach (array_values($fila) as $i => $celda) {
                $ancho = $anchos[$i] ?? 25;

                $this->Cell(
                    $ancho, 6, $this->ajustar((string) $celda, $ancho),
                    'B', 0, $alineacion[$i] ?? 'L', $alterna
                );
            }
            $this->Ln();
            $alterna = !$alterna;
        }
    }

    /**
     * Paneles en fila: el resumen que se consulta de un vistazo.
     *
     * Cada panel lleva su cabecera de color y dentro renglones de
     * etiqueta y valor. Todos salen de la misma altura —la del que más
     * renglones tenga— porque tres cajas desiguales se leen como tres
     * documentos distintos pegados.
     *
     * El valor encoge antes que desbordarse: `Cell` no recorta, y un
     * importe que se sale del panel se imprime encima del de al lado.
     *
     * @param array<int,array{titulo:string,filas:array<int,array{0:string,1:string,2?:bool}>}> $paneles
     */
    public function panelesResumen(array $paneles, float $hueco = 4.0): void
    {
        if ($paneles === []) {
            return;
        }

        $n      = count($paneles);
        $util   = $this->GetPageWidth() - 28;
        $ancho  = ($util - $hueco * ($n - 1)) / $n;

        $altoCab  = 6.2;
        $altoFila = 4.7;
        $maximo   = 0;

        foreach ($paneles as $p) {
            $maximo = max($maximo, count($p['filas']));
        }

        $altoCuerpo = $maximo * $altoFila + 3.0;
        $total      = $altoCab + $altoCuerpo;

        // Un panel partido entre dos páginas no es un panel.
        if ($this->GetY() + $total > $this->GetPageHeight() - 26) {
            $this->AddPage();
        }

        $y0 = $this->GetY();

        foreach (array_values($paneles) as $i => $p) {
            $x = 14 + $i * ($ancho + $hueco);

            // Cuerpo primero: la cabecera se dibuja encima de su borde.
            [$br, $bg, $bb] = self::BORDE;
            $this->SetDrawColor($br, $bg, $bb);
            $this->SetFillColor(255, 255, 255);
            $this->Rect($x, $y0, $ancho, $total, 'DF');

            [$r, $g, $b] = self::MARCA;
            $this->SetFillColor($r, $g, $b);
            $this->Rect($x, $y0, $ancho, $altoCab, 'F');

            $this->SetXY($x, $y0);
            $this->SetFont('Helvetica', 'B', 6.8);
            $this->SetTextColor(255, 255, 255);
            $this->celdaEncogida($ancho, $altoCab, mb_strtoupper($p['titulo']), 'C', 0, 6.8, 5.0);

            $y = $y0 + $altoCab + 1.5;

            foreach ($p['filas'] as $fila) {
                $fuerte = (bool) ($fila[2] ?? false);

                $this->SetXY($x + 2.2, $y);
                $this->SetFont('Helvetica', '', 6.4);
                [$r, $g, $b] = self::GRIS;
                $this->SetTextColor($r, $g, $b);
                $this->celdaEncogida($ancho * 0.56 - 2.2, $altoFila, (string) $fila[0], 'L', 0, 6.4, 5.0);

                $this->SetFont('Helvetica', 'B', $fuerte ? 7.6 : 7.0);
                [$r, $g, $b] = self::TINTA;
                $this->SetTextColor($r, $g, $b);
                $this->celdaEncogida(
                    $ancho * 0.44 - 2.2, $altoFila, (string) $fila[1], 'R', 0,
                    $fuerte ? 7.6 : 7.0, 5.0
                );

                $y += $altoFila;
            }
        }

        $this->SetXY(14, $y0 + $total + 3.0);
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Termómetro de uso de la línea.
     *
     * Decir «uso 73.4%» no le dice nada a quien no tiene con qué
     * compararlo. Dibujado sobre su escala, con el tramo cómodo, el de
     * cuidado y el de riesgo marcados, se entiende sin explicación.
     */
    public function termometro(float $pct, string $pie = ''): void
    {
        $pct   = max(0.0, min(100.0, $pct));
        $util  = $this->GetPageWidth() - 28;
        $alto  = 5.0;
        $y     = $this->GetY();

        if ($y + 20 > $this->GetPageHeight() - 26) {
            $this->AddPage();
            $y = $this->GetY();
        }

        // La escala: cómodo hasta 60, cuidado hasta 85, riesgo arriba.
        $tramos = [[60.0, [18, 183, 106]], [85.0, [247, 144, 9]], [100.0, [217, 45, 32]]];
        $desde  = 0.0;

        foreach ($tramos as [$hasta, $color]) {
            // Apagados: es la escala, no el dato. El dato es la barra
            // llena que se dibuja encima.
            $this->SetFillColor(
                (int) round(255 - (255 - $color[0]) * 0.22),
                (int) round(255 - (255 - $color[1]) * 0.22),
                (int) round(255 - (255 - $color[2]) * 0.22)
            );
            $this->Rect(14 + $util * $desde / 100, $y, $util * ($hasta - $desde) / 100, $alto, 'F');
            $desde = $hasta;
        }

        // Lo consumido, en el color del tramo donde cae.
        $color = $pct <= 60 ? [18, 183, 106] : ($pct <= 85 ? [247, 144, 9] : [217, 45, 32]);
        $this->SetFillColor($color[0], $color[1], $color[2]);
        $this->Rect(14, $y, max(0.6, $util * $pct / 100), $alto, 'F');

        // La marca de dónde quedó, para leerla contra la escala.
        $x = 14 + $util * $pct / 100;
        $this->SetFillColor(32, 38, 52);
        $this->Rect(max(14.0, min(14 + $util - 0.9, $x - 0.45)), $y - 1.6, 0.9, $alto + 3.2, 'F');

        // Los cortes de la escala, debajo.
        $this->SetY($y + $alto + 0.8);
        $this->SetFont('Helvetica', '', 5.8);
        [$r, $g, $b] = self::GRIS;
        $this->SetTextColor($r, $g, $b);

        foreach ([0 => '0%', 60 => '60%', 85 => '85%', 100 => '100%'] as $marca => $txt) {
            $this->SetXY(14 + $util * $marca / 100 - 5, $y + $alto + 0.8);
            $this->Cell(10, 3, $this->txt($txt), 0, 0, (int) $marca === 0 ? 'L' : ((int) $marca === 100 ? 'R' : 'C'));
        }

        $this->SetXY(14, $y + $alto + 4.4);

        if ($pie !== '') {
            $this->SetFont('Helvetica', '', 7.0);
            $this->MultiCell($util, 3.6, $this->txt($pie), 0, 'L');
        }

        $this->SetTextColor(0, 0, 0);
        $this->Ln(1);
    }

    /**
     * Tabla cuyas cabeceras caben aunque sean largas.
     *
     * «Fecha de transacción» no entra en una columna de 20 mm en una
     * sola línea, y `tabla()` la recortaría a «Fecha de tr...». Aquí la
     * cabecera admite un salto de línea explícito y la franja crece.
     *
     * @param array<int,string>            $cabeceras  Con "\n" donde parte
     * @param array<int,array<int,string>> $filas
     * @param array<int,float>             $anchos
     * @param array<int,string>            $alineacion
     */
    public function tablaDetalle(
        array $cabeceras,
        array $filas,
        array $anchos,
        array $alineacion = [],
        float $cuerpo = 6.8
    ): void {
        $lineas = 1;

        foreach ($cabeceras as $c) {
            $lineas = max($lineas, substr_count($c, "\n") + 1);
        }

        $altoCab = 3.4 * $lineas + 2.2;

        $pintarCabecera = function () use ($cabeceras, $anchos, $alineacion, $altoCab, $lineas): void {
            [$r, $g, $b] = self::MARCA;
            $this->SetFillColor($r, $g, $b);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Helvetica', 'B', 6.2);

            $y = $this->GetY();
            $x = 14.0;

            foreach ($cabeceras as $i => $c) {
                $w = $anchos[$i] ?? 25;
                $this->Rect($x, $y, $w, $altoCab, 'F');

                $partes = explode("\n", $c);
                $salto  = ($altoCab - 3.4 * count($partes)) / 2;

                foreach ($partes as $j => $parte) {
                    $this->SetXY($x, $y + $salto + $j * 3.4);
                    $this->celdaEncogida(
                        $w, 3.4, mb_strtoupper($parte), $alineacion[$i] ?? 'L', 0, 6.2, 4.6
                    );
                }

                $x += $w;
            }

            $this->SetXY(14, $y + $altoCab);
        };

        $pintarCabecera();

        $this->SetFont('Helvetica', '', $cuerpo);
        $this->SetTextColor(0, 0, 0);
        [$br, $bg, $bb] = self::BORDE;
        $this->SetDrawColor($br, $bg, $bb);

        $alterna = false;

        foreach ($filas as $fila) {
            if ($this->GetY() > $this->GetPageHeight() - 30) {
                $this->AddPage();
                $pintarCabecera();
                $this->SetFont('Helvetica', '', $cuerpo);
                $this->SetTextColor(0, 0, 0);
            }

            [$tr, $tg, $tb] = self::MARCA_TENUE;
            $this->SetFillColor($tr, $tg, $tb);

            foreach (array_values($fila) as $i => $celda) {
                $w = $anchos[$i] ?? 25;
                $this->Cell($w, 5.4, $this->ajustar((string) $celda, $w), 'B', 0,
                    $alineacion[$i] ?? 'L', $alterna);
            }

            $this->Ln();
            $alterna = !$alterna;
        }
    }

    /**
     * Recuadro de letra pequeña: lo que la ley obliga a decir.
     *
     * Va encuadrado y aparte a propósito. Mezclado con el resto se lee
     * como relleno; en su caja se lee como lo que es.
     *
     * @param array<int,string> $puntos
     */
    public function cajaLegal(string $titulo, array $puntos): void
    {
        $util = $this->GetPageWidth() - 28;

        $this->Ln(2);
        $this->SetFont('Helvetica', 'B', 7.2);
        [$r, $g, $b] = self::TINTA;
        $this->SetTextColor($r, $g, $b);
        $this->Cell($util, 4.6, $this->txt(mb_strtoupper($titulo)), 0, 1, 'L');

        [$br, $bg, $bb] = self::BORDE;
        $this->SetDrawColor($br, $bg, $bb);

        $y0 = $this->GetY();
        $this->SetY($y0 + 1.4);

        $this->SetFont('Helvetica', '', 6.4);
        [$r, $g, $b] = self::GRIS;
        $this->SetTextColor($r, $g, $b);

        foreach ($puntos as $p) {
            $this->SetX(17.5);
            // La viñeta aparte: dentro del MultiCell la sangría se
            // pierde en la segunda línea y el punto queda huérfano.
            $this->Cell(3, 3.4, $this->txt('-'), 0, 0, 'L');
            $this->MultiCell($util - 6.5, 3.4, $this->txt($p), 0, 'J');
            $this->Ln(0.8);
        }

        $y1 = $this->GetY();
        $this->Rect(14, $y0, $util, $y1 - $y0 + 0.6, 'D');

        $this->SetY($y1 + 2.5);
        $this->SetTextColor(0, 0, 0);
    }

    /** Recuadro destacado con un total. */
    public function totalDestacado(string $etiqueta, string $valor, bool $negativo = false): void
    {
        $this->Ln(2);
        $ancho = $this->GetPageWidth() - 28;

        [$fr, $fg, $fb] = self::MARCA_TENUE;
        $this->SetFillColor($fr, $fg, $fb);
        $this->Rect(14, $this->GetY(), $ancho, 12, 'F');

        $this->SetY($this->GetY() + 2.5);
        $this->SetX(18);
        $this->SetFont('Helvetica', 'B', 9);
        [$r, $g, $b] = self::GRIS;
        $this->SetTextColor($r, $g, $b);
        $this->Cell($ancho * 0.6, 7, $this->txt($etiqueta), 0, 0, 'L');

        $this->SetFont('Helvetica', 'B', 12);
        if ($negativo) {
            $this->SetTextColor(180, 35, 24);
        } else {
            [$r, $g, $b] = self::TINTA;
            $this->SetTextColor($r, $g, $b);
        }
        $this->Cell($ancho * 0.4 - 8, 7, $this->txt($valor), 0, 1, 'R');

        $this->SetTextColor(0, 0, 0);
        $this->Ln(3);
    }

    public function aviso(string $texto): void
    {
        $this->Ln(2);
        $this->SetFont('Helvetica', '', 7.5);
        [$r, $g, $b] = self::GRIS;
        $this->SetTextColor($r, $g, $b);
        $this->MultiCell(0, 4, $this->txt($texto), 0, 'L');
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Ajusta un texto al ancho de su columna.
     *
     * `Cell` de FPDF no recorta ni ajusta: si el texto es más ancho que
     * la celda, se sale por encima de la siguiente y el documento queda
     * con columnas pisadas. Por eso cada sitio recortaba por su cuenta a
     * un número fijo de caracteres —24 en el comprobante—, y un nombre
     * de 25 perdía la última letra: «Jenifer Abigail Castañed».
     *
     * Medir es lo correcto: un nombre cabe o no cabe según su ancho real,
     * no según cuántas letras tenga. Solo si de verdad no cabe se
     * recorta, y entonces se dice con puntos suspensivos en lugar de
     * dejar una palabra partida que parece un error de datos.
     */
    public function ajustar(string $texto, float $ancho): string
    {
        $t = $this->txt($texto);

        // Margen interno de la celda: FPDF deja ~1mm a cada lado.
        $util = max(2.0, $ancho - 2.0);

        if ($this->GetStringWidth($t) <= $util) {
            return $t;
        }

        $puntos = $this->txt('…');
        $margen = $this->GetStringWidth($puntos);

        while ($t !== '' && $this->GetStringWidth($t) + $margen > $util) {
            $t = substr($t, 0, -1);
        }

        return rtrim($t) . $puntos;
    }

    /**
     * Párrafo de texto corrido, justificado.
     *
     * Un contrato no es un cuadro de datos: es texto que se lee de
     * principio a fin. Sin esto, el clausulado había que armarlo a base
     * de `aviso()`, que usa letra gris de 7.5 pt pensada para notas al
     * pie, no para algo que alguien va a firmar.
     */
    public function parrafo(string $texto, float $tamano = 8.5, string $estilo = ''): void
    {
        $this->SetFont('Helvetica', $estilo, $tamano);
        [$r, $g, $b] = self::TINTA;
        $this->SetTextColor($r, $g, $b);
        $this->MultiCell(0, 4.2, $this->txt($texto), 0, 'J');
        $this->Ln(2);
    }

    /**
     * Cláusula numerada: su título en una línea y el texto debajo.
     *
     * El título va en morado y en negrita para que el documento se
     * pueda recorrer con la vista buscando una cláusula concreta, que
     * es como se consulta un contrato en la práctica: nadie lo relee
     * entero para mirar la mora.
     */
    public function clausula(string $titulo, string $texto): void
    {
        // Una cláusula no debe partirse justo en su título: si no caben
        // al menos el encabezado y dos líneas, empieza en la siguiente.
        if ($this->GetY() > $this->GetPageHeight() - 34) {
            $this->AddPage();
        }

        $this->Ln(1);
        $this->SetFont('Helvetica', 'B', 8.5);
        [$r, $g, $b] = self::MARCA;
        $this->SetTextColor($r, $g, $b);
        $this->MultiCell(0, 4.6, $this->txt($titulo), 0, 'L');

        $this->SetFont('Helvetica', '', 8.5);
        [$r, $g, $b] = self::TINTA;
        $this->SetTextColor($r, $g, $b);
        $this->MultiCell(0, 4.2, $this->txt($texto), 0, 'J');
        $this->Ln(1.5);
    }

    /** Sello de verificación con el hash del documento. */
    public function sello(string $hash): void
    {
        $this->Ln(4);
        $ancho = $this->GetPageWidth() - 28;

        [$r, $g, $b] = self::BORDE;
        $this->SetDrawColor($r, $g, $b);
        $this->Rect(14, $this->GetY(), $ancho, 14);

        $this->SetY($this->GetY() + 2);
        $this->SetX(17);
        $this->SetFont('Helvetica', 'B', 7);
        [$r, $g, $b] = self::TINTA;
        $this->SetTextColor($r, $g, $b);
        $this->Cell(0, 4, $this->txt('VERIFICACION DE INTEGRIDAD'), 0, 1, 'L');

        $this->SetX(17);
        $this->SetFont('Courier', '', 6.5);
        [$r, $g, $b] = self::GRIS;
        $this->SetTextColor($r, $g, $b);
        $this->Cell(0, 4, 'SHA-256: ' . $hash, 0, 1, 'L');

        $this->SetX(17);
        $this->SetFont('Helvetica', '', 6.5);
        $this->Cell(0, 3.5, $this->txt(
            'Este codigo identifica el contenido exacto del documento. '
            . 'Si se altera, el codigo deja de coincidir.'
        ), 0, 1, 'L');

        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    /**
     * Guarda el PDF en storage/pdf/ y devuelve la ruta.
     *
     * El nombre se sanea: un nombre con ../ escribiría fuera del
     * directorio previsto.
     */
    public function guardar(string $nombre): string
    {
        $limpio = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre) ?: 'documento';
        if (!str_ends_with($limpio, '.pdf')) {
            $limpio .= '.pdf';
        }

        $dir = CM_STORAGE . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $ruta = $dir . DIRECTORY_SEPARATOR . $limpio;
        $this->Output('F', $ruta);

        return $ruta;
    }
}
