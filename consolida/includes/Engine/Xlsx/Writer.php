<?php
/**
 * Escritor mínimo de archivos .xlsx sin librerías externas.
 *
 * Genera un libro válido (OOXML transicional) que abre sin avisos en Excel, LibreOffice y Google Sheets:
 * varias hojas, texto, números y booleanos, primera fila en negrita, anchos de columna y formatos numéricos simples.
 * El ZIP se arma en memoria (no usa archivos temporales): `build()` devuelve los bytes y `save()` los escribe.
 *
 *   $bytes = Writer::build([
 *       'Datos' => [['Campo', 'Valor'], ['Empresa', 'E1'], ['Año', 2027]],          // forma corta: lista de filas
 *       'PyG'   => [
 *           'rows'    => [['Cuenta', 'Nombre', 'Monto'], ['41-01', 'Ventas', 1250000.5]],
 *           'widths'  => [12, 40, 18],         // ancho por columna (caracteres); por defecto se calcula
 *           'formats' => [2 => '#,##0.00'],    // formato por columna: '#,##0', '#,##0.00', '0%', '0.00%', 'general'
 *           'bold_header' => true,             // primera fila en negrita (por defecto true)
 *       ],
 *   ]);
 *
 * Filas: la fila i del arreglo (0, 1, 2…) es la fila i+1 de Excel; una fila vacía [] deja un hueco. Dentro de la
 * fila, las claves enteras son la columna base 0 (permite huecos: [0 => 'A', 3 => 'D']). null y '' no se escriben.
 *
 * @package Consolida
 */

declare(strict_types=1);

namespace Consolida\Engine\Xlsx;

final class Writer
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PKG  = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** Estilos (índices de cellXfs en styles.xml). */
    private const STYLE_NORMAL  = 0;
    private const STYLE_BOLD    = 1;
    private const STYLE_FORMATS = [
        '#,##0'    => 2, // numFmtId 3 (integrado)
        '#,##0.00' => 3, // numFmtId 4
        '0%'       => 4, // numFmtId 9
        '0.00%'    => 5, // numFmtId 10
    ];

    /** Fecha fija de los metadatos del ZIP: el mismo contenido produce los mismos bytes. */
    private const ZIP_DATE = [2026, 1, 1, 0, 0, 0];

    /**
     * Construye el .xlsx y devuelve sus bytes.
     *
     * @param array<string, array> $sheets Nombre de hoja => filas, o ['rows' => …, 'widths' => …, 'formats' => …].
     * @throws \InvalidArgumentException Si no hay hojas o un nombre de hoja no es válido para Excel.
     */
    public static function build(array $sheets): string
    {
        if (!$sheets) {
            throw new \InvalidArgumentException('El libro debe tener al menos una hoja.');
        }

        $strings = [];   // texto => índice en sharedStrings
        $count   = 0;    // número total de referencias a textos (atributo count)
        $sheetXml = [];
        $names = [];
        $seen = [];
        foreach ($sheets as $name => $spec) {
            $name = self::checkSheetName((string) $name);
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Hay dos hojas con el nombre «' . $name . '».');
            }
            $seen[$key] = true;
            $names[] = $name;
            $spec = self::normalizeSpec($spec);
            $sheetXml[] = self::sheetXml($spec, count($sheetXml) === 0, $strings, $count);
        }

        $n = count($names);
        $files = [];
        $files['[Content_Types].xml'] = self::contentTypes($n);
        $files['_rels/.rels'] = self::xmlHeader()
            . '<Relationships xmlns="' . self::NS_PKG . '">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
        $files['docProps/core.xml'] = self::xmlHeader()
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Consolida</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">2026-01-01T00:00:00Z</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">2026-01-01T00:00:00Z</dcterms:modified>'
            . '</cp:coreProperties>';
        $files['docProps/app.xml'] = self::xmlHeader()
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Application>Consolida</Application></Properties>';
        $files['xl/workbook.xml'] = self::workbookXml($names);
        $files['xl/_rels/workbook.xml.rels'] = self::workbookRels($n);
        $files['xl/styles.xml'] = self::stylesXml();
        $files['xl/sharedStrings.xml'] = self::sharedStringsXml($strings, $count);
        foreach ($sheetXml as $i => $xml) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $xml;
        }

        return self::zip($files);
    }

    /**
     * Construye el .xlsx y lo guarda en $path.
     *
     * @throws \RuntimeException Si no se puede escribir el archivo.
     */
    public static function save(string $path, array $sheets): void
    {
        $bytes = self::build($sheets);
        if (@file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('No se pudo escribir el archivo ' . basename($path) . '.');
        }
    }

    /**
     * Letras de columna para un índice base 0 (0 → A, 26 → AA).
     */
    public static function columnLetters(int $index): string
    {
        $s = '';
        $index++;
        while ($index > 0) {
            $m = ($index - 1) % 26;
            $s = chr(65 + $m) . $s;
            $index = intdiv($index - 1, 26);
        }
        return $s;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Hojas
    // ------------------------------------------------------------------------------------------------------------

    /** Acepta la forma corta (lista de filas) o la larga (['rows' => …]). */
    private static function normalizeSpec($spec): array
    {
        if (!is_array($spec)) {
            throw new \InvalidArgumentException('Cada hoja debe ser un arreglo de filas.');
        }
        if (!array_key_exists('rows', $spec)) {
            $spec = ['rows' => $spec];
        }
        $formats = [];
        foreach ((array) ($spec['formats'] ?? []) as $col => $fmt) {
            if ($fmt === 'general' || $fmt === null) {
                continue;
            }
            if (!isset(self::STYLE_FORMATS[$fmt])) {
                throw new \InvalidArgumentException('Formato numérico no soportado: ' . $fmt);
            }
            $formats[(int) $col] = self::STYLE_FORMATS[$fmt];
        }
        return [
            'rows'        => array_values((array) $spec['rows']),
            'widths'      => isset($spec['widths']) ? (array) $spec['widths'] : null,
            'formats'     => $formats,
            'bold_header' => (bool) ($spec['bold_header'] ?? true),
        ];
    }

    /** Excel: 1–31 caracteres, sin : \ / ? * [ ] y sin apóstrofo al inicio o al final. */
    private static function checkSheetName(string $name): string
    {
        $len = mb_strlen($name, 'UTF-8');
        if ($len < 1 || $len > 31 || preg_match('/[:\\\\\/?*\[\]]/u', $name) || $name[0] === "'" || substr($name, -1) === "'") {
            throw new \InvalidArgumentException('Nombre de hoja no válido para Excel: «' . $name . '».');
        }
        if (strcasecmp($name, 'History') === 0) {
            throw new \InvalidArgumentException('Excel reserva el nombre de hoja «History».');
        }
        return $name;
    }

    /**
     * XML de una hoja. Registra los textos en la tabla compartida.
     *
     * @param array<string, int> $strings
     */
    private static function sheetXml(array $spec, bool $selected, array &$strings, int &$count): string
    {
        $data = '';
        $maxCol = -1;
        $maxRow = 0;
        $lengths = []; // largo máximo mostrado por columna, para el ancho automático

        foreach ($spec['rows'] as $i => $row) {
            if (!is_array($row) || !$row) {
                continue;
            }
            $r = $i + 1;
            $cells = '';
            ksort($row);
            foreach ($row as $c => $value) {
                $c = (int) $c;
                if ($c < 0 || $c > 16383) {
                    throw new \InvalidArgumentException('Columna fuera de rango en la fila ' . $r . '.');
                }
                if ($value === null || $value === '') {
                    continue;
                }
                $ref = self::columnLetters($c) . $r;
                $header = $r === 1 && $spec['bold_header'];
                $style = $header ? self::STYLE_BOLD : self::STYLE_NORMAL;

                if (is_bool($value)) {
                    $cells .= '<c r="' . $ref . '" t="b"' . self::styleAttr($style) . '><v>' . ($value ? '1' : '0') . '</v></c>';
                    $shown = $value ? 'VERDADERO' : 'FALSO';
                } elseif ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
                    if (!$header && isset($spec['formats'][$c])) {
                        $style = $spec['formats'][$c];
                    }
                    $num = self::numberText($value);
                    $cells .= '<c r="' . $ref . '"' . self::styleAttr($style) . '><v>' . $num . '</v></c>';
                    $shown = $style === self::STYLE_FORMATS['#,##0']
                        ? number_format((float) $value, 0)
                        : ($style === self::STYLE_FORMATS['#,##0.00'] ? number_format((float) $value, 2) : $num);
                } else {
                    // Texto (y cualquier otro valor convertido a texto, incluidos NAN/INF).
                    $text = self::cleanText(is_scalar($value) || $value instanceof \Stringable ? (string) $value : json_encode($value));
                    if ($text === '') {
                        continue;
                    }
                    if (!isset($strings[$text])) {
                        $strings[$text] = count($strings);
                    }
                    $count++;
                    $cells .= '<c r="' . $ref . '" t="s"' . self::styleAttr($style) . '><v>' . $strings[$text] . '</v></c>';
                    $shown = $text;
                }
                $maxCol = max($maxCol, $c);
                $lengths[$c] = max($lengths[$c] ?? 0, self::displayLength($shown, $header));
            }
            if ($cells !== '') {
                $data .= '<row r="' . $r . '">' . $cells . '</row>';
                $maxRow = $r;
            }
        }

        // Anchos: los indicados, o automáticos entre 8 y 60 caracteres.
        $cols = '';
        $widthCount = max($maxCol + 1, $spec['widths'] !== null ? count($spec['widths']) : 0);
        for ($c = 0; $c < $widthCount; $c++) {
            if ($spec['widths'] !== null && isset($spec['widths'][$c]) && is_numeric($spec['widths'][$c])) {
                $w = max(1.0, min(255.0, (float) $spec['widths'][$c]));
            } elseif (isset($lengths[$c])) {
                $w = max(8, min(60, $lengths[$c] + 2));
            } else {
                continue;
            }
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . self::numberText(round($w, 2)) . '" customWidth="1"/>';
        }

        $dimension = $maxRow > 0 && $maxCol >= 0 ? 'A1:' . self::columnLetters($maxCol) . $maxRow : 'A1';

        return self::xmlHeader()
            . '<worksheet xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
            . '<dimension ref="' . $dimension . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"' . ($selected ? ' tabSelected="1"' : '') . '/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . ($data !== '' ? '<sheetData>' . $data . '</sheetData>' : '<sheetData/>')
            . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            . '</worksheet>';
    }

    private static function styleAttr(int $style): string
    {
        return $style === self::STYLE_NORMAL ? '' : ' s="' . $style . '"';
    }

    /** Largo aproximado en caracteres (la negrita ocupa un poco más). */
    private static function displayLength(string $text, bool $bold): int
    {
        $max = 0;
        foreach (explode("\n", $text) as $line) {
            $max = max($max, mb_strlen($line, 'UTF-8'));
        }
        return $bold ? (int) ceil($max * 1.1) : $max;
    }

    /** Número en notación que Excel acepta: sin separadores, punto decimal, precisión completa. */
    private static function numberText($value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if ($value == floor($value) && abs($value) < 1e15) {
            return sprintf('%.0f', $value);
        }
        $s = var_export($value, true); // representación más corta que conserva el valor exacto
        return str_replace(['E+', 'e+'], ['E', 'E'], $s);
    }

    /**
     * Texto apto para XML: UTF-8 válido, sin caracteres de control prohibidos, máximo 32 767 caracteres (límite de
     * Excel por celda).
     */
    private static function cleanText(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        $text = str_replace("\r\n", "\n", $text);
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
        if (mb_strlen($text, 'UTF-8') > 32767) {
            $text = mb_substr($text, 0, 32767, 'UTF-8');
        }
        return $text;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Partes del paquete
    // ------------------------------------------------------------------------------------------------------------

    private static function xmlHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    }

    private static function contentTypes(int $sheets): string
    {
        $xml = self::xmlHeader()
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        for ($i = 1; $i <= $sheets; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
        return $xml;
    }

    /** @param string[] $names */
    private static function workbookXml(array $names): string
    {
        $sheets = '';
        foreach ($names as $i => $name) {
            $sheets .= '<sheet name="' . self::esc($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return self::xmlHeader()
            . '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
            . '<bookViews><workbookView activeTab="0"/></bookViews>'
            . '<sheets>' . $sheets . '</sheets>'
            . '</workbook>';
    }

    private static function workbookRels(int $sheets): string
    {
        $xml = self::xmlHeader() . '<Relationships xmlns="' . self::NS_PKG . '">';
        for ($i = 1; $i <= $sheets; $i++) {
            $xml .= '<Relationship Id="rId' . $i . '" Type="' . self::NS_REL . '/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $xml .= '<Relationship Id="rId' . ($sheets + 1) . '" Type="' . self::NS_REL . '/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId' . ($sheets + 2) . '" Type="' . self::NS_REL . '/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
        return $xml;
    }

    /**
     * Estilos: 0 normal, 1 negrita, 2 #,##0, 3 #,##0.00, 4 0 %, 5 0,00 % (formatos integrados de Excel, que cada
     * programa muestra con los separadores de su configuración regional).
     */
    private static function stylesXml(): string
    {
        $xf = static fn(int $numFmt, int $font) => '<xf numFmtId="' . $numFmt . '" fontId="' . $font . '" fillId="0" borderId="0" xfId="0"'
            . ($numFmt ? ' applyNumberFormat="1"' : '') . ($font ? ' applyFont="1"' : '') . '/>';
        return self::xmlHeader()
            . '<styleSheet xmlns="' . self::NS_MAIN . '">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font>'
            . '<font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font>'
            . '</fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">' . $xf(0, 0) . $xf(0, 1) . $xf(3, 0) . $xf(4, 0) . $xf(9, 0) . $xf(10, 0) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
            . '</styleSheet>';
    }

    /** @param array<string, int> $strings */
    private static function sharedStringsXml(array $strings, int $count): string
    {
        $xml = self::xmlHeader()
            . '<sst xmlns="' . self::NS_MAIN . '" count="' . $count . '" uniqueCount="' . count($strings) . '">';
        foreach ($strings as $text => $_) {
            $text = (string) $text; // las claves numéricas de PHP vuelven a texto
            // Espacios al inicio o al final, saltos de línea o tabuladores: hay que conservarlos.
            $preserve = $text !== trim($text) || strpbrk($text, "\n\t") !== false;
            $xml .= '<si><t' . ($preserve ? ' xml:space="preserve"' : '') . '>' . self::esc($text) . '</t></si>';
        }
        return $xml . '</sst>';
    }

    // ------------------------------------------------------------------------------------------------------------
    // ZIP en memoria
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Arma un ZIP (método deflate si zlib está disponible; si no, sin compresión, que Excel también acepta).
     *
     * @param array<string, string> $files ruta => contenido
     */
    private static function zip(array $files): string
    {
        [$y, $mo, $d, $h, $mi, $s] = self::ZIP_DATE;
        $dosTime = ($h << 11) | ($mi << 5) | intdiv($s, 2);
        $dosDate = (($y - 1980) << 9) | ($mo << 5) | $d;
        $canDeflate = function_exists('gzdeflate');

        $out = '';
        $central = '';
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $method = 0;
            $data = $content;
            if ($canDeflate && $size > 0) {
                $deflated = gzdeflate($content, 6);
                if ($deflated !== false && strlen($deflated) < $size) {
                    $method = 8;
                    $data = $deflated;
                }
            }
            $csize = strlen($data);
            $offset = strlen($out);

            // Cabecera local: firma, versión 2.0, bandera UTF-8 (bit 11), método, fecha, CRC y tamaños.
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate, $crc, $csize, $size, strlen($name), 0)
                . $name . $data;

            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, $csize, $size,
                    strlen($name), 0, 0, 0, 0, 0, $offset)
                . $name;
        }
        $n = count($files);
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($out), 0);
        return $out . $central . $end;
    }
}
