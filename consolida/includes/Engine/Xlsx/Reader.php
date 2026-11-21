<?php
/**
 * Lector de archivos .xlsx sin librerías externas (ZipArchive + XMLReader).
 *
 * Devuelve las celdas con valor de cada hoja indexadas por número de fila real (1 = primera fila de Excel) y por
 * índice de columna base 0 (A = 0). Las celdas vacías no aparecen, por eso las filas y columnas pueden tener huecos.
 *
 *   Reader::read('/ruta/archivo.xlsx');
 *   // ['Datos' => [1 => [0 => 'Campo', 1 => 'Valor'], 2 => [0 => 'Empresa', 1 => 'E1']], 'PyG' => [...]]
 *
 * Tipos devueltos: string (texto), int|float (números), bool (booleanos). Las fórmulas devuelven su último valor
 * calculado (el que Excel guardó en el archivo). Los errores de fórmula (#DIV/0!) se devuelven como texto.
 *
 * Probado con archivos guardados por Excel, LibreOffice, Google Sheets y por nuestro Writer.
 *
 * @package Consolida
 */

declare(strict_types=1);

namespace Consolida\Engine\Xlsx;

final class Reader
{
    /** Tamaño máximo descomprimido de cada parte XML que se lee (protección contra zip bombs). */
    public const MAX_PART_BYTES = 10 * 1024 * 1024;

    /** Número máximo de hojas que se aceptan en un libro. */
    public const MAX_SHEETS = 255;

    /** Estado de libxml_use_internal_errors() antes de leer, para restaurarlo. */
    private static bool $prevErrors = false;

    /**
     * Lee el libro completo, o solo las hojas pedidas.
     *
     * @param string        $path  Ruta del archivo .xlsx.
     * @param string[]|null $only  Nombres de hoja a leer (sin distinguir mayúsculas); null = todas.
     * @return array<string, array<int, array<int, string|int|float|bool>>>
     * @throws \RuntimeException Si el archivo no es un .xlsx válido o excede los límites.
     */
    public static function read(string $path, ?array $only = null): array
    {
        $zip = self::open($path);
        try {
            $sheets  = self::sheetIndex($zip);
            $strings = null; // las cadenas compartidas se cargan solo si alguna hoja las usa
            $wanted  = null;
            if ($only !== null) {
                $wanted = array_map(static fn($n) => mb_strtolower((string) $n, 'UTF-8'), $only);
            }

            $out = [];
            foreach ($sheets as $sheet) {
                if ($wanted !== null && !in_array(mb_strtolower($sheet['name'], 'UTF-8'), $wanted, true)) {
                    continue;
                }
                if ($strings === null) {
                    $strings = self::sharedStrings($zip);
                }
                $xml = self::part($zip, $sheet['path'], 'la hoja «' . $sheet['name'] . '»');
                $out[$sheet['name']] = self::parseSheet($xml, $strings, $sheet['name']);
            }
            return $out;
        } finally {
            $zip->close();
        }
    }

    /**
     * Nombres de las hojas de cálculo en el orden del libro.
     *
     * @return string[]
     */
    public static function sheetNames(string $path): array
    {
        $zip = self::open($path);
        try {
            return array_column(self::sheetIndex($zip), 'name');
        } finally {
            $zip->close();
        }
    }

    /**
     * Convierte una referencia de columna («A», «AB», «XFD») en índice base 0.
     */
    public static function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Apertura y estructura del paquete
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Abre el ZIP verificando antes que no sea otro formato (xls antiguo, csv, pdf…).
     */
    private static function open(string $path): \ZipArchive
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('No se encontró el archivo o no se puede leer.');
        }
        $fh = fopen($path, 'rb');
        $magic = $fh ? (string) fread($fh, 8) : '';
        if ($fh) {
            fclose($fh);
        }
        if (strncmp($magic, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0) {
            throw new \RuntimeException('El archivo está en formato .xls antiguo o protegido con contraseña. Ábralo en Excel y guárdelo como .xlsx sin contraseña.');
        }
        if (strncmp($magic, "PK\x03\x04", 4) !== 0) {
            throw new \RuntimeException('El archivo no es un Excel .xlsx válido.');
        }

        $zip = new \ZipArchive();
        $res = $zip->open($path, \ZipArchive::RDONLY);
        if ($res !== true) {
            throw new \RuntimeException('El archivo .xlsx está dañado y no se puede abrir (código ZIP ' . (int) $res . ').');
        }
        return $zip;
    }

    /**
     * Lista de hojas de cálculo: [['name' => 'Datos', 'path' => 'xl/worksheets/sheet1.xml'], …].
     * Sigue la cadena oficial: _rels/.rels → workbook.xml → workbook.xml.rels.
     *
     * @return array<int, array{name: string, path: string}>
     */
    private static function sheetIndex(\ZipArchive $zip): array
    {
        // 1) Localizar el workbook (casi siempre xl/workbook.xml, pero se respeta lo que diga _rels/.rels).
        $workbookPath = 'xl/workbook.xml';
        if (self::exists($zip, '_rels/.rels')) {
            foreach (self::relationships(self::part($zip, '_rels/.rels', 'las relaciones del paquete'), '') as $rel) {
                if (self::typeIs($rel['type'], 'officeDocument')) {
                    $workbookPath = $rel['target'];
                    break;
                }
            }
        }
        $workbookPath = self::resolveName($zip, $workbookPath);
        if ($workbookPath === null) {
            throw new \RuntimeException('El archivo no es un Excel .xlsx válido (falta el libro de trabajo).');
        }

        // 2) Relaciones del workbook: rId → ruta de la hoja.
        $baseDir  = self::dirName($workbookPath);
        $relsPath = ($baseDir === '' ? '' : $baseDir . '/') . '_rels/' . basename($workbookPath) . '.rels';
        $targets  = [];
        if (self::exists($zip, $relsPath)) {
            foreach (self::relationships(self::part($zip, $relsPath, 'las relaciones del libro'), $baseDir) as $id => $rel) {
                if (self::typeIs($rel['type'], 'worksheet')) {
                    $targets[$id] = $rel['target'];
                }
            }
        }

        // 3) Hojas en el orden del workbook. Se ignoran hojas de gráfico y de macros (no tienen celdas).
        $xml = self::part($zip, $workbookPath, 'el libro de trabajo');
        $r = self::xmlReader($xml);
        $sheets = [];
        $position = 0;
        while ($r->read()) {
            if ($r->nodeType !== \XMLReader::ELEMENT || $r->localName !== 'sheet') {
                continue;
            }
            $position++;
            $name = null;
            $rid  = null;
            if ($r->moveToFirstAttribute()) {
                do {
                    if ($r->localName === 'name' && $r->prefix === '') {
                        $name = $r->value;
                    } elseif ($r->localName === 'id' && $r->prefix !== '') {
                        $rid = $r->value; // r:id (el prefijo puede variar según el programa)
                    }
                } while ($r->moveToNextAttribute());
                $r->moveToElement();
            }
            if ($name === null) {
                continue;
            }
            $path = $rid !== null && isset($targets[$rid]) ? $targets[$rid] : null;
            if ($path === null && $rid === null) {
                // Archivo sin relaciones: se usa la convención sheetN.xml.
                $path = ($baseDir === '' ? '' : $baseDir . '/') . 'worksheets/sheet' . $position . '.xml';
            }
            $path = $path !== null ? self::resolveName($zip, $path) : null;
            if ($path === null) {
                continue; // chartsheet, dialogsheet o relación rota: no hay datos que leer
            }
            $sheets[] = ['name' => $name, 'path' => $path];
            if (count($sheets) > self::MAX_SHEETS) {
                throw new \RuntimeException('El archivo tiene demasiadas hojas.');
            }
        }
        self::finish($r, 'el libro de trabajo');

        if (!$sheets) {
            throw new \RuntimeException('El archivo .xlsx no contiene hojas de cálculo.');
        }
        return $sheets;
    }

    /**
     * Lee un archivo .rels: [Id => ['type' => …, 'target' => ruta normalizada dentro del ZIP]].
     *
     * @return array<string, array{type: string, target: string}>
     */
    private static function relationships(string $xml, string $baseDir): array
    {
        $r = self::xmlReader($xml);
        $out = [];
        while ($r->read()) {
            if ($r->nodeType !== \XMLReader::ELEMENT || $r->localName !== 'Relationship') {
                continue;
            }
            if (strcasecmp((string) $r->getAttribute('TargetMode'), 'External') === 0) {
                continue;
            }
            $id     = (string) $r->getAttribute('Id');
            $target = (string) $r->getAttribute('Target');
            $out[$id] = [
                'type'   => (string) $r->getAttribute('Type'),
                'target' => self::joinPath($baseDir, $target),
            ];
        }
        self::finish($r, 'las relaciones');
        return $out;
    }

    /** El tipo de relación termina en «/$suffix» (vale para OOXML transicional y estricto). */
    private static function typeIs(string $type, string $suffix): bool
    {
        return substr($type, -strlen($suffix) - 1) === '/' . $suffix;
    }

    /**
     * Une un destino relativo con su carpeta base y normaliza «.» y «..». Un destino que empieza con «/» es
     * absoluto desde la raíz del paquete.
     */
    private static function joinPath(string $baseDir, string $target): string
    {
        $target = str_replace('\\', '/', rawurldecode($target));
        $full = $target !== '' && $target[0] === '/' ? substr($target, 1) : ($baseDir === '' ? $target : $baseDir . '/' . $target);
        $parts = [];
        foreach (explode('/', $full) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    private static function dirName(string $path): string
    {
        $pos = strrpos($path, '/');
        return $pos === false ? '' : substr($path, 0, $pos);
    }

    private static function exists(\ZipArchive $zip, string $name): bool
    {
        return $zip->locateName($name) !== false;
    }

    /**
     * Devuelve el nombre exacto de la entrada del ZIP (tolera diferencias de mayúsculas y rutas relativas a la raíz
     * que escriben algunos generadores), o null si no existe.
     */
    private static function resolveName(\ZipArchive $zip, string $name): ?string
    {
        foreach ([$name, ltrim($name, '/')] as $candidate) {
            $idx = $zip->locateName($candidate);
            if ($idx === false) {
                $idx = $zip->locateName($candidate, \ZipArchive::FL_NOCASE);
            }
            if ($idx !== false) {
                return (string) $zip->getNameIndex($idx);
            }
        }
        // Algunos generadores escriben «xl/worksheets/…» relativo a la raíz aunque el workbook esté en xl/.
        if (str_starts_with($name, 'xl/xl/')) {
            return self::resolveName($zip, substr($name, 3));
        }
        return null;
    }

    /**
     * Lee una entrada del ZIP con límite de tamaño descomprimido.
     */
    private static function part(\ZipArchive $zip, string $name, string $label): string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            throw new \RuntimeException('El archivo .xlsx está incompleto: falta ' . $label . '.');
        }
        if ((int) $stat['size'] > self::MAX_PART_BYTES) {
            throw new \RuntimeException('El archivo es demasiado grande: ' . $label . ' supera los 10 MB sin comprimir.');
        }
        // Se pide un byte más del límite por si el tamaño declarado en el ZIP es falso.
        $data = $zip->getFromName($name, self::MAX_PART_BYTES + 1);
        if ($data === false) {
            throw new \RuntimeException('El archivo .xlsx está dañado: no se pudo leer ' . $label . '.');
        }
        if (strlen($data) > self::MAX_PART_BYTES) {
            throw new \RuntimeException('El archivo es demasiado grande: ' . $label . ' supera los 10 MB sin comprimir.');
        }
        return $data;
    }

    // ------------------------------------------------------------------------------------------------------------
    // XML
    // ------------------------------------------------------------------------------------------------------------

    /**
     * XMLReader seguro: sin red, sin DTD (rechaza DOCTYPE para evitar entidades expandidas y XXE).
     */
    private static function xmlReader(string $xml): \XMLReader
    {
        $head = substr($xml, 0, 2048);
        if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
            throw new \RuntimeException('El archivo .xlsx contiene XML no permitido.');
        }
        // Los errores de libxml se recogen en finish(), que restaura el estado anterior.
        self::$prevErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $r = new \XMLReader();
        if (!$r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_use_internal_errors(self::$prevErrors);
            throw new \RuntimeException('El archivo .xlsx contiene XML dañado.');
        }
        return $r;
    }

    /** Cierra el lector y lanza excepción si libxml encontró errores fatales. */
    private static function finish(\XMLReader $r, string $label): void
    {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors(self::$prevErrors);
        $r->close();
        foreach ($errors as $e) {
            if ($e->level >= LIBXML_ERR_ERROR) {
                throw new \RuntimeException('El archivo .xlsx está dañado: XML inválido en ' . $label . ' (línea ' . $e->line . ').');
            }
        }
    }

    /**
     * Tabla de cadenas compartidas (xl/sharedStrings.xml). Une los fragmentos de texto enriquecido y omite las
     * guías fonéticas (rPh).
     *
     * @return string[]
     */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $name = null;
        // Buscar la parte por su relación sería lo estricto; en la práctica siempre está en una de estas rutas.
        foreach (['xl/sharedStrings.xml', 'xl/SharedStrings.xml', 'xl/sharedstrings.xml'] as $candidate) {
            $name = self::resolveName($zip, $candidate);
            if ($name !== null) {
                break;
            }
        }
        if ($name === null) {
            return [];
        }
        $r = self::xmlReader(self::part($zip, $name, 'la tabla de textos'));
        $out = [];
        $current = null;
        $inT = false;
        $rph = 0;
        while ($r->read()) {
            $type = $r->nodeType;
            if ($type === \XMLReader::ELEMENT) {
                $ln = $r->localName;
                if ($ln === 'si') {
                    $current = '';
                    if ($r->isEmptyElement) {
                        $out[] = '';
                        $current = null;
                    }
                } elseif ($ln === 'rPh') {
                    if (!$r->isEmptyElement) {
                        $rph++;
                    }
                } elseif ($ln === 't' && $current !== null && $rph === 0 && !$r->isEmptyElement) {
                    $inT = true;
                }
            } elseif ($type === \XMLReader::END_ELEMENT) {
                $ln = $r->localName;
                if ($ln === 't') {
                    $inT = false;
                } elseif ($ln === 'rPh') {
                    $rph = max(0, $rph - 1);
                } elseif ($ln === 'si' && $current !== null) {
                    $out[] = $current;
                    $current = null;
                }
            } elseif ($inT && self::isText($type)) {
                $current .= $r->value;
            }
        }
        self::finish($r, 'la tabla de textos');
        return $out;
    }

    private static function isText(int $type): bool
    {
        return $type === \XMLReader::TEXT || $type === \XMLReader::CDATA
            || $type === \XMLReader::SIGNIFICANT_WHITESPACE || $type === \XMLReader::WHITESPACE;
    }

    /**
     * Recorre <sheetData> con una máquina de estados (sin cargar la hoja completa en memoria como árbol).
     *
     * @param string[] $strings
     * @return array<int, array<int, string|int|float|bool>>
     */
    private static function parseSheet(string $xml, array $strings, string $sheetName): array
    {
        $r = self::xmlReader($xml);
        $rows = [];

        $rowNum = 0;        // fila actual (real)
        $lastRow = 0;       // última fila vista, para filas sin atributo r
        $col = -1;          // columna actual (base 0)
        $rowHasRef = true;  // la fila actual trae atributo r
        $cellType = 'n';
        $inCell = false;
        $inV = false;
        $inIs = false;
        $inT = false;
        $rph = 0;
        $vText = '';
        $isText = '';
        $hasV = false;
        $hasIs = false;

        while ($r->read()) {
            $type = $r->nodeType;
            if ($type === \XMLReader::ELEMENT) {
                switch ($r->localName) {
                    case 'row':
                        $attr = $r->getAttribute('r');
                        $rowHasRef = $attr !== null && ctype_digit($attr);
                        $rowNum = $rowHasRef ? (int) $attr : $lastRow + 1;
                        $lastRow = $rowNum;
                        $col = -1;
                        break;
                    case 'c':
                        $ref = (string) $r->getAttribute('r');
                        if ($ref !== '' && preg_match('/^\$?([A-Za-z]{1,3})\$?(\d+)$/', $ref, $m)) {
                            $col = self::columnIndex($m[1]);
                            if (!$rowHasRef && (int) $m[2] > 0 && (int) $m[2] !== $rowNum) {
                                // Fila sin atributo r: manda la referencia de la celda.
                                $rowNum = (int) $m[2];
                                $lastRow = $rowNum;
                            }
                        } else {
                            $col++; // celda sin referencia: la siguiente columna
                        }
                        $cellType = (string) ($r->getAttribute('t') ?? 'n');
                        $vText = '';
                        $isText = '';
                        $hasV = false;
                        $hasIs = false;
                        $inCell = !$r->isEmptyElement;
                        break;
                    case 'v':
                        if ($inCell) {
                            $hasV = true;
                            $inV = !$r->isEmptyElement;
                        }
                        break;
                    case 'is':
                        if ($inCell) {
                            $hasIs = true;
                            $inIs = !$r->isEmptyElement;
                        }
                        break;
                    case 'rPh':
                        if (!$r->isEmptyElement) {
                            $rph++;
                        }
                        break;
                    case 't':
                        if ($inIs && $rph === 0 && !$r->isEmptyElement) {
                            $inT = true;
                        }
                        break;
                }
            } elseif ($type === \XMLReader::END_ELEMENT) {
                switch ($r->localName) {
                    case 'v':
                        $inV = false;
                        break;
                    case 't':
                        $inT = false;
                        break;
                    case 'rPh':
                        $rph = max(0, $rph - 1);
                        break;
                    case 'is':
                        $inIs = false;
                        break;
                    case 'c':
                        if ($inCell && $rowNum > 0 && $col >= 0) {
                            $value = self::cellValue($cellType, $hasV ? $vText : null, $hasIs ? $isText : null, $strings);
                            if ($value !== null && $value !== '') {
                                $rows[$rowNum][$col] = $value;
                            }
                        }
                        $inCell = false;
                        break;
                    case 'sheetData':
                        break 2; // lo que sigue (fusiones, formatos) no interesa
                }
            } elseif (self::isText($type)) {
                if ($inV) {
                    $vText .= $r->value;
                } elseif ($inT) {
                    $isText .= $r->value;
                }
            }
        }
        self::finish($r, 'la hoja «' . $sheetName . '»');

        ksort($rows);
        foreach ($rows as &$cells) {
            ksort($cells);
        }
        unset($cells);
        return $rows;
    }

    /**
     * Valor PHP de una celda según su tipo OOXML.
     *
     * @param string[] $strings
     * @return string|int|float|bool|null
     */
    private static function cellValue(string $type, ?string $v, ?string $inline, array $strings)
    {
        switch ($type) {
            case 's': // cadena compartida
                if ($v === null) {
                    return null;
                }
                $i = (int) trim($v);
                return $strings[$i] ?? null;
            case 'inlineStr':
                return $inline ?? $v;
            case 'str': // resultado de fórmula de texto
            case 'e':   // error de fórmula (#N/A, #DIV/0!…)
            case 'd':   // fecha ISO 8601 (poco común)
                return $v;
            case 'b':
                return $v === null ? null : trim($v) === '1' || strcasecmp(trim($v), 'true') === 0;
            default: // 'n' o sin tipo
                if ($v === null) {
                    return $inline; // algunos generadores ponen <is> sin t="inlineStr"
                }
                return self::number(trim($v));
        }
    }

    /**
     * Número guardado en <v>: entero si no tiene parte decimal ni exponente y cabe sin perder precisión.
     *
     * @return int|float|string|null
     */
    private static function number(string $v)
    {
        if ($v === '') {
            return null;
        }
        if (preg_match('/^-?\d{1,15}$/', $v)) {
            return (int) $v;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        return $v; // valor no numérico en una celda numérica: se devuelve tal cual
    }
}
