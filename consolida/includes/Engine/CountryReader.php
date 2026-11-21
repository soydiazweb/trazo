<?php
/**
 * Lector de archivos de país: Excel del país + perfil de lectura (§5) + Corporate (§4) → Dataset normalizado (§6)
 * con los issues de LECTURA (§7): FMT_SCALE_DECL, FMT_TEXT_NUMBER, FMT_DATE, FMT_VERSION, CAT_UNKNOWN, CAT_PRODUCT,
 * CAT_CHANNEL, DATA_EMPTY, DATA_DUP.
 *
 *   $ds = CountryReader::read('/ruta/Mexico_Presupuesto_2027.xlsx', Profiles::defaults()['MX'], $corporate);
 *   $ds['pl'][0];  // ['concept'=>'volume:Snacks','label'=>'Volumen Snacks','month'=>1,'value'=>168186.0,
 *                  //  'src'=>['sheet'=>'resultados','row'=>2,'col'=>'C']]
 *
 * Reglas:
 *  - Montos en moneda local y en UNIDADES: se aplica la escala (MX y US ×1000) salvo volúmenes y precios. La escala
 *    esperada es parametros_paises.escala_archivo; la declarada sale de la portada («Montos en»), del encabezado de la
 *    columna («Ventas (miles MXN)») o de la columna Unidad de cada fila. Se aplica lo que declara el archivo (es lo que
 *    describe sus números) y si difiere de la esperada se reporta FMT_SCALE_DECL.
 *  - ic.amount queda en la moneda pactada, sin escala (los archivos la escriben completa: 100,000.00 USD).
 *  - 1502 Depreciación acumulada se guarda positiva (valor absoluto; si viene negativa no es error).
 *  - Fechas: nombres de mes ES/EN («Ene», «Jan-2027»), dd/mm/aaaa o mm/dd/aaaa (según el perfil; si solo es válida
 *    en el otro orden se acepta y se avisa), aaaa-mm, seriales de Excel, «Saldo inicial», «Opening 12/31/2026»,
 *    «Opening Balance». En hojas de saldos (balance, inventario_grupo) el 31/12 del año anterior es el saldo inicial
 *    (mes 0). Una fecha fuera del año de la versión se toma con su número de mes en el año de la versión y se reporta
 *    FMT_DATE (crítico): así el archivo se lee completo y el hallazgo queda a la vista.
 *  - Etiqueta desconocida → CAT_UNKNOWN; sus valores no entran a pl/bs (no se adivina) y quedan en 'unmapped'.
 *  - Celda vacía donde debe haber valor → DATA_EMPTY y value = null (la fila sigue presente).
 *  - Concepto repetido en el mismo mes → DATA_DUP; se conserva el primero.
 *  - Nunca lanza excepción por datos raros; solo si el archivo no se puede abrir (la lanza Xlsx\Reader).
 *
 * PHP puro: sin funciones de WordPress.
 *
 * @package Consolida
 */

declare(strict_types=1);

namespace Consolida\Engine;

use Consolida\Engine\Xlsx\Reader;

final class CountryReader
{
    /** Severidad y categoría de cada código de issue de lectura. */
    public const ISSUE_META = [
        'FMT_SCALE_DECL'  => ['critico', 'formato_escala'],
        'FMT_TEXT_NUMBER' => ['aviso', 'formato_escala'],
        'FMT_DATE'        => ['critico', 'catalogo_periodo'],
        'FMT_VERSION'     => ['critico', 'catalogo_periodo'],
        'CAT_UNKNOWN'     => ['critico', 'catalogo_periodo'],
        'CAT_PRODUCT'     => ['critico', 'catalogo_periodo'],
        'CAT_CHANNEL'     => ['critico', 'catalogo_periodo'],
        'DATA_EMPTY'      => ['critico', 'calculos_totales'],
        'DATA_DUP'        => ['critico', 'catalogo_periodo'],
    ];

    /** Hojas que todo archivo de país debe traer (indicadores_reportados es opcional: solo 2027). */
    public const REQUIRED_SHEETS = ['resultados', 'balance', 'intercompania', 'ventas_detalle', 'inventario_grupo', 'capex'];

    /** Nombres de mes (normalizados) → número. */
    private const MONTH_NAMES = [
        'ene' => 1, 'enero' => 1, 'jan' => 1, 'january' => 1,
        'feb' => 2, 'febrero' => 2, 'february' => 2,
        'mar' => 3, 'marzo' => 3, 'march' => 3,
        'abr' => 4, 'abril' => 4, 'apr' => 4, 'april' => 4,
        'may' => 5, 'mayo' => 5,
        'jun' => 6, 'junio' => 6, 'june' => 6,
        'jul' => 7, 'julio' => 7, 'july' => 7,
        'ago' => 8, 'agosto' => 8, 'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'set' => 9, 'septiembre' => 9, 'setiembre' => 9, 'september' => 9,
        'oct' => 10, 'octubre' => 10, 'october' => 10,
        'nov' => 11, 'noviembre' => 11, 'november' => 11,
        'dic' => 12, 'diciembre' => 12, 'dec' => 12, 'december' => 12,
    ];

    private const MONTH_LABELS = [
        0 => 'saldo inicial', 1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    private const REPORTED_AMOUNTS = ['ebit', 'nopat', 'nowc_avg', 'net_fixed_avg', 'ic_avg', 'ce_avg', 'eva_local', 'eva_usd'];

    // Estado de una lectura ------------------------------------------------------------------------------------------

    private array $profile;
    private array $corporate;
    private array $maps = [];        // mapas normalizados: nombre → [norm(etiqueta) => valor]
    private array $issues = [];
    private string $company;
    private ?string $version = null;
    private ?int $year = null;
    private float $factor = 1.0;     // escala aplicada a montos
    private string $scaleKey = 'unidades';
    private array $textNumbers = []; // hoja => [count, row, col, raw]
    private array $dateIssues = [];  // agregados por hoja + texto de la fecha
    private array $scaleIssues = []; // agregados por hoja + unidad
    private array $unknown = [];     // CAT_UNKNOWN agregados por hoja + etiqueta (formato largo)

    private function __construct(array $profile, array $corporate)
    {
        $this->profile   = $profile;
        $this->corporate = $corporate;
        $this->company   = strtoupper((string) ($profile['company'] ?? ''));
        foreach ((array) ($profile['maps'] ?? []) as $name => $map) {
            $this->maps[$name] = [];
            if ($name === 'opening') {
                foreach ((array) $map as $alias) {
                    $this->maps[$name][self::norm((string) $alias)] = true;
                }
                uksort($this->maps[$name], static fn($a, $b) => strlen($b) <=> strlen($a));
                continue;
            }
            foreach ((array) $map as $label => $value) {
                $this->maps[$name][self::norm((string) $label)] = $value;
            }
        }
    }

    /**
     * Lee un archivo de país.
     *
     * @param string      $path      Ruta del .xlsx.
     * @param array       $profile   Perfil del país (Profiles::defaults()['GT'] o el guardado en la base).
     * @param array       $corporate Resultado de Corporate::load().
     * @param string|null $fileName  Nombre original del archivo (si $path es un temporal de subida).
     * @throws \RuntimeException Solo si el archivo no se puede abrir como .xlsx.
     */
    public static function read(string $path, array $profile, array $corporate, ?string $fileName = null): array
    {
        $book = Reader::read($path);
        $self = new self($profile, $corporate);
        return $self->run($book, $fileName ?? basename($path));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Flujo principal
    // ------------------------------------------------------------------------------------------------------------

    private function run(array $book, string $fileName): array
    {
        $sheets = [];
        foreach ($book as $name => $rows) {
            $sheets[self::norm((string) $name)] = ['name' => (string) $name, 'rows' => $rows];
        }
        $sheetFor = function (string $key) use ($sheets): ?array {
            $cfg = $this->profile['sheets'][$key] ?? [];
            $name = self::norm((string) ($cfg['sheet'] ?? $key));
            return $sheets[$name] ?? null;
        };

        $companyInfo = $this->corporate['companies'][$this->company] ?? [];

        // Portada, versión y año ----------------------------------------------------------------------------------
        $cover = $this->readCover($sheetFor('portada'));
        $this->resolveVersion($fileName, $cover);

        // Escala ----------------------------------------------------------------------------------------------------
        $expected = (string) ($companyInfo['scale'] ?? $this->profile['scale'] ?? 'unidades');
        $declared = isset($cover['amounts_in']) ? Corporate::scaleKey((string) $cover['amounts_in']) : null;
        $this->scaleKey = $declared ?? $expected;
        $this->factor = self::scaleFactor($this->scaleKey);
        if ($declared !== null && $declared !== $expected) {
            $this->issue('FMT_SCALE_DECL', [
                'sheet' => $cover['_src']['amounts_in']['sheet'] ?? 'portada',
                'row'   => $cover['_src']['amounts_in']['row'] ?? null,
                'col'   => $cover['_src']['amounts_in']['col'] ?? null,
                'message' => sprintf(
                    'La portada dice que los montos están en «%s» (%s), pero para %s se esperan montos en %s (parametros_paises). Se leyó con la escala declarada en el archivo (×%s).',
                    $cover['amounts_in'], $declared, $this->company, $expected, self::fmt($this->factor, 0)
                ),
            ]);
        }

        foreach (self::REQUIRED_SHEETS as $required) {
            if ($sheetFor($required) === null) {
                $this->issue('DATA_EMPTY', [
                    'sheet' => $required,
                    'message' => 'El archivo no trae la hoja «' . $required . '».',
                ]);
            }
        }

        $pl = $this->readStatement($sheetFor('resultados'), 'resultados', 'pl');
        $bs = $this->readStatement($sheetFor('balance'), 'balance', 'bs');

        $ds = [
            'meta' => [
                'company'        => $this->company,
                'company_name'   => $cover['company_name'] ?? ($companyInfo['name'] ?? null),
                'version'        => $this->version,
                'year'           => $this->year,
                'currency'       => $companyInfo['currency'] ?? (isset($cover['currency']) ? strtoupper((string) $cover['currency']) : null),
                'scale_declared' => $declared ?? $expected,
                'scale_expected' => $expected,
                'scale_source'   => $declared !== null ? 'portada' : 'parametros_paises',
                'scale_applied'  => $this->factor == 1.0 ? 1 : (int) $this->factor,
                'scenario'       => $cover['scenario'] ?? null,
                'prepared_by'    => $cover['prepared_by'] ?? null,
                'loaded_at'      => $this->isoDate($cover['loaded_at'] ?? null),
                'approved_by'    => $cover['approved_by'] ?? null,
                'approved_at'    => $this->isoDate($cover['approved_at'] ?? null),
                'notes'          => $cover['notes'] ?? null,
                'file'           => basename($fileName),
                'profile_schema' => $this->profile['schema'] ?? null,
            ],
            'pl'           => $pl['rows'],
            'bs'           => $bs['rows'],
            'sales'        => $this->readSales($sheetFor('ventas_detalle')),
            'ic'           => $this->readIntercompany($sheetFor('intercompania')),
            'ic_inventory' => $this->readInventory($sheetFor('inventario_grupo')),
            'capex'        => $this->readCapex($sheetFor('capex')),
            'reported'     => $this->readReported($sheetFor('indicadores_reportados')),
            'unmapped'     => array_merge($pl['unmapped'], $bs['unmapped']),
            'issues'       => [],
        ];

        $this->flush();
        $ds['issues'] = $this->issues;
        return $ds;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Portada y versión
    // ------------------------------------------------------------------------------------------------------------

    private function readCover(?array $sheet): array
    {
        $out = ['_src' => []];
        if ($sheet === null) {
            return $out;
        }
        $cfg  = $this->profile['sheets']['portada'] ?? [];
        $kCol = Reader::columnIndex((string) ($cfg['key_col'] ?? 'A'));
        $vCol = Reader::columnIndex((string) ($cfg['value_col'] ?? 'B'));
        foreach ($sheet['rows'] as $rowNum => $cells) {
            $key = $cells[$kCol] ?? null;
            if (!isset($out['_title']) && is_string($key) && trim($key) !== '') {
                $out['_title'] = trim($key); // primera línea: «Meridia México · Presupuesto 2027 v1»
            }
            if ($key === null || !array_key_exists($vCol, $cells)) {
                continue;
            }
            $field = $this->maps['cover'][self::norm((string) $key)] ?? null;
            if ($field === null || isset($out[$field])) {
                continue;
            }
            $val = $cells[$vCol];
            $out[$field] = is_string($val) ? trim($val) : $val;
            $out['_src'][$field] = ['sheet' => $sheet['name'], 'row' => (int) $rowNum, 'col' => self::colLetter($vCol)];
        }
        return $out;
    }

    /** Versión y año: del nombre del archivo (§1) y de la portada (Escenario o título); si difieren, FMT_VERSION. */
    private function resolveVersion(string $fileName, array $cover): void
    {
        $fromName  = $this->versionFrom(pathinfo($fileName, PATHINFO_FILENAME));
        $fromCover = null;
        foreach (['scenario', '_title'] as $field) {
            if (isset($cover[$field]) && is_string($cover[$field])) {
                $fromCover = $this->versionFrom($cover[$field]);
                if ($fromCover !== null) {
                    break;
                }
            }
        }
        $this->version = $fromName ?? $fromCover;
        if ($fromName !== null && $fromCover !== null && $fromName !== $fromCover) {
            $src = $cover['_src']['scenario'] ?? ['sheet' => 'portada', 'row' => null, 'col' => null];
            $this->issue('FMT_VERSION', $src + [
                'message' => sprintf(
                    'El nombre del archivo indica la versión %s, pero la portada dice «%s» (%s). Se usó la del nombre del archivo.',
                    $fromName, $cover['scenario'] ?? $cover['_title'], $fromCover
                ),
            ]);
        }
        if ($this->version !== null && preg_match('/(\d{4})/', $this->version, $m)) {
            $this->year = (int) $m[1];
        }
    }

    private function versionFrom(string $text): ?string
    {
        return self::detectVersion($text, $this->corporate);
    }

    /**
     * «Mexico_Presupuesto_2027_v2.xlsx», «Budget 2027 v1», «Real/estimado 2026», «Actual 2026» → clave de versión
     * (real_2026, ppto_2026, ppto_2027_v1, ppto_2027_v2); null si el texto no la indica.
     */
    public static function detectVersion(string $text, array $corporate = []): ?string
    {
        $t = self::norm(str_replace('_', ' ', preg_replace('/\.xlsx$/i', '', $text) ?? $text));
        if (!preg_match('/(presupuesto|budget|ppto|real|actual|forecast|estimado)\D{0,12}?(20\d\d)(?:\D{0,3}v\s*(\d+))?/', $t, $m)) {
            return null;
        }
        return self::versionKey(in_array($m[1], ['real', 'actual', 'estimado'], true) ? 'real' : 'ppto', (int) $m[2], isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null, $corporate);
    }

    /**
     * Clave de versión igual a tasas_oficiales.version: real_2026, ppto_2026, ppto_2027_v1, ppto_2027_v2. Un
     * presupuesto sin «vN» es ppto_<año>_v1 cuando el corporativo tiene esa clave (y no ppto_<año>).
     */
    public static function versionKey(string $kind, int $year, ?int $v, array $corporate): string
    {
        if ($kind === 'real') {
            return 'real_' . $year;
        }
        if ($v !== null) {
            return 'ppto_' . $year . '_v' . $v;
        }
        $rates = $corporate['rates'] ?? [];
        if (!isset($rates['ppto_' . $year]) && isset($rates['ppto_' . $year . '_v1'])) {
            return 'ppto_' . $year . '_v1';
        }
        return 'ppto_' . $year;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Resultados y balance
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @return array{rows: array, unmapped: array}
     */
    private function readStatement(?array $sheet, string $key, string $kind): array
    {
        if ($sheet === null) {
            return ['rows' => [], 'unmapped' => []];
        }
        $cfg = $this->profile['sheets'][$key] ?? [];
        $res = ($cfg['layout'] ?? 'wide') === 'long'
            ? $this->readLong($sheet, $cfg, $kind)
            : $this->readWide($sheet, $cfg, $kind);
        if (!$res['rows'] && !$res['unmapped']) {
            $this->issue('DATA_EMPTY', ['sheet' => $sheet['name'], 'message' => 'La hoja «' . $sheet['name'] . '» no tiene datos que se puedan leer.']);
        }
        return $res;
    }

    private function readWide(array $sheet, array $cfg, string $kind): array
    {
        $rows      = $sheet['rows'];
        $name      = $sheet['name'];
        $labelCol  = Reader::columnIndex((string) ($cfg['label_col'] ?? 'A'));
        $isStock   = $kind === 'bs';
        $map       = $this->maps[$kind] ?? [];

        // Fila de encabezado: la pista del perfil si tiene meses; si no, la primera fila con ≥ 3 periodos.
        $headerRow = null;
        $hint = (int) ($cfg['header_row'] ?? 0);
        $candidates = $hint > 0 ? array_merge([$hint], array_keys($rows)) : array_keys($rows);
        foreach ($candidates as $r) {
            if (!isset($rows[$r]) || $r > 30) {
                continue;
            }
            $n = 0;
            foreach ($rows[$r] as $c => $v) {
                if ($c !== $labelCol && $this->parsePeriod($v) !== null) {
                    $n++;
                }
            }
            if ($n >= 3) {
                $headerRow = (int) $r;
                break;
            }
        }
        if ($headerRow === null) {
            $this->issue('FMT_DATE', ['sheet' => $name, 'message' => 'No se encontró en «' . $name . '» la fila de encabezado con los meses.']);
            return ['rows' => [], 'unmapped' => []];
        }

        // Columnas → mes.
        $colMonth = [];
        $usedBy   = [];
        foreach ($rows[$headerRow] as $c => $v) {
            if ($c === $labelCol) {
                continue;
            }
            $letter = self::colLetter($c);
            $p = $this->parsePeriod($v);
            if ($p === null) {
                if (is_string($v) && trim($v) !== '') {
                    $this->issue('FMT_DATE', ['sheet' => $name, 'row' => $headerRow, 'col' => $letter,
                        'message' => sprintf('El encabezado «%s» de %s!%s%d no es un mes reconocible; la columna no se leyó.', trim($v), $name, $letter, $headerRow)]);
                }
                continue;
            }
            $m = $this->resolveMonth($p, $isStock, $name, $headerRow, $letter);
            if ($m === null) {
                continue;
            }
            if (isset($usedBy[$m])) {
                $this->issue('FMT_DATE', ['sheet' => $name, 'row' => $headerRow, 'col' => $letter, 'month' => $m,
                    'message' => sprintf('El mes %s aparece dos veces en el encabezado de «%s» (columnas %s y %s); se leyó solo la columna %s.',
                        self::monthLabel($m), $name, $usedBy[$m], $letter, $usedBy[$m])]);
                continue;
            }
            $usedBy[$m] = $letter;
            $colMonth[$c] = $m;
        }
        foreach ($this->expectedMonths($isStock) as $m) {
            if (!isset($usedBy[$m])) {
                $this->issue('FMT_DATE', ['sheet' => $name, 'row' => $headerRow, 'month' => $m,
                    'message' => sprintf('Falta la columna de %s en «%s».', self::monthLabel($m), $name)]);
            }
        }

        $out = [];
        $unmapped = [];
        $seen = [];
        foreach ($rows as $r => $cells) {
            if ($r <= $headerRow) {
                continue;
            }
            $label = $cells[$labelCol] ?? null;
            $label = $label === null ? '' : trim((string) $label);
            $hasValues = false;
            foreach ($colMonth as $c => $m) {
                if (isset($cells[$c]) && $cells[$c] !== '') {
                    $hasValues = true;
                    break;
                }
            }
            if ($label === '' && !$hasValues) {
                continue;
            }
            $concept = $label === '' ? null : ($map[self::norm($label)] ?? null);
            if ($concept === null) {
                if (!$hasValues) {
                    continue; // título de sección sin números
                }
                $total = 0.0;
                $vals = [];
                foreach ($colMonth as $c => $m) {
                    $v = $this->number($cells[$c] ?? null, $name, (int) $r, self::colLetter($c));
                    $v = $v === null ? null : self::scaled($v, $this->factor);
                    $vals[] = ['concept' => null, 'label' => $label, 'month' => $m, 'value' => $v,
                        'src' => ['sheet' => $name, 'row' => (int) $r, 'col' => self::colLetter($c)]];
                    if ($v !== null && ($isStock ? $m === 12 : $m > 0)) {
                        $total += $v;
                    }
                }
                array_push($unmapped, ...$vals);
                $this->issue('CAT_UNKNOWN', ['sheet' => $name, 'row' => (int) $r, 'col' => self::colLetter($labelCol),
                    'actual' => $total,
                    'message' => $label === ''
                        ? sprintf('La fila %d de «%s» tiene montos pero no tiene nombre de cuenta; no se sumó.', $r, $name)
                        : sprintf('La cuenta «%s» (%s!%s%d) no está en el catálogo del grupo ni en el perfil de lectura de %s; no se sumó. %s %s.',
                            $label, $name, self::colLetter($labelCol), $r, $this->company,
                            $isStock ? 'Saldo a diciembre:' : 'Total del año:', self::fmt($total))]);
                continue;
            }
            if (isset($seen[$concept]) && $concept !== 'total_row') {
                // Fila repetida (misma etiqueta o dos etiquetas del mismo concepto): un solo DATA_DUP por fila.
                $first = reset($seen[$concept]);
                $this->issue('DATA_DUP', ['sheet' => $name, 'row' => (int) $r, 'col' => self::colLetter($labelCol), 'concept' => (string) $concept,
                    'message' => sprintf('«%s» (%s) está dos veces en «%s»: filas %d y %d. Se tomó la fila %d.',
                        $label, $concept, $name, $first['src']['row'], $r, $first['src']['row'])]);
                continue;
            }
            foreach ($colMonth as $c => $m) {
                $letter = self::colLetter($c);
                $src = ['sheet' => $name, 'row' => (int) $r, 'col' => $letter];
                $raw = $cells[$c] ?? null;
                $value = $this->number($raw, $name, (int) $r, $letter);
                if ($value === null) {
                    $this->emptyCell($raw, $src, $m, $concept, $label);
                }
                $value = $this->scaleValue($value, $concept, null, $src);
                $seen[$concept][$m] = ['src' => $src, 'value' => $value, 'label' => $label];
                $out[] = ['concept' => (string) $concept, 'label' => $label, 'month' => $m, 'value' => $value, 'src' => $src];
            }
        }
        return ['rows' => $out, 'unmapped' => $unmapped];
    }

    private function readLong(array $sheet, array $cfg, string $kind): array
    {
        $name    = $sheet['name'];
        $cols    = $this->columns($cfg, ['period' => 'A', 'label' => 'B', 'value' => 'C']);
        $isStock = $kind === 'bs';
        $map     = $this->maps[$kind] ?? [];
        $header  = (int) ($cfg['header_row'] ?? 1);

        $out = [];
        $unmapped = [];
        $seen = [];
        $labels = [];
        foreach ($sheet['rows'] as $r => $cells) {
            if ($r <= $header) {
                continue;
            }
            $r = (int) $r;
            $periodRaw = $cells[$cols['period']] ?? null;
            $labelRaw  = $cells[$cols['label']] ?? null;
            $valueRaw  = $cells[$cols['value']] ?? null;
            $label = $labelRaw === null ? '' : trim(is_float($labelRaw) && floor($labelRaw) === $labelRaw ? (string) (int) $labelRaw : (string) $labelRaw);
            if ($periodRaw === null && $label === '' && $valueRaw === null) {
                continue;
            }
            $periodLetter = self::colLetter($cols['period']);
            $valueLetter  = self::colLetter($cols['value']);
            $src = ['sheet' => $name, 'row' => $r, 'col' => $valueLetter];

            // Mes: la columna Tipo («Saldo inicial» / «Cierre de mes») manda si existe.
            $m = null;
            $kindRaw = isset($cols['kind']) ? ($cells[$cols['kind']] ?? null) : null;
            $p = $this->parsePeriod($periodRaw);
            if ($kindRaw !== null && $this->isOpening((string) $kindRaw)) {
                $p = ['y' => $p['y'] ?? null, 'm' => $p['m'] ?? null, 'opening' => true, 'raw' => trim((string) $periodRaw) . ' · ' . trim((string) $kindRaw), 'swapped' => false];
            }
            if ($p === null) {
                $this->issue('FMT_DATE', ['sheet' => $name, 'row' => $r, 'col' => $periodLetter,
                    'message' => sprintf('El periodo «%s» de %s!%s%d no se reconoce como fecha; la fila no se leyó.',
                        trim((string) $periodRaw), $name, $periodLetter, $r)]);
                continue;
            }
            $m = $this->resolveMonth($p, $isStock, $name, $r, $periodLetter);
            if ($m === null) {
                continue;
            }

            $concept = $label === '' ? null : ($map[self::norm($label)] ?? null);
            $value = $this->number($valueRaw, $name, $r, $valueLetter);
            $unit  = isset($cols['unit']) ? ($cells[$cols['unit']] ?? null) : null;

            if ($concept === null) {
                $v = $value === null ? null : self::scaled($value, $this->unitFactor($unit) ?? $this->factor);
                $unmapped[] = ['concept' => null, 'label' => $label, 'month' => $m, 'value' => $v, 'src' => $src];
                $k = $name . '|' . self::norm($label);
                $this->unknown[$k] ??= ['sheet' => $name, 'row' => $r, 'col' => self::colLetter($cols['label']), 'label' => $label, 'n' => 0, 'total' => 0.0, 'stock' => $isStock, 'last' => -1];
                $this->unknown[$k]['n']++;
                if ($v !== null) {
                    if ($isStock) {
                        if ($m >= $this->unknown[$k]['last']) {
                            $this->unknown[$k]['total'] = $v;
                            $this->unknown[$k]['last'] = $m;
                        }
                    } else {
                        $this->unknown[$k]['total'] += $v;
                    }
                }
                continue;
            }
            if ($value === null) {
                $this->emptyCell($valueRaw, $src, $m, $concept, $label);
            }
            $value = $this->scaleValue($value, $concept, $unit, $src);
            if ($concept !== 'total_row') {
                if (isset($seen[$concept][$m])) {
                    $this->duplicate($concept, $label, $m, $seen[$concept][$m], $src, $value);
                    continue;
                }
                $seen[$concept][$m] = ['src' => $src, 'value' => $value, 'label' => $label];
                $labels[$concept] ??= ['label' => $label, 'row' => $r];
            }
            $out[] = ['concept' => (string) $concept, 'label' => $label, 'month' => $m, 'value' => $value, 'src' => $src];
        }

        // Meses faltantes por concepto, agrupados: si a varios conceptos les faltan los mismos meses, un solo issue.
        $expected = $this->expectedMonths($isStock);
        $groups = [];
        foreach ($seen as $concept => $months) {
            $missing = array_values(array_diff($expected, array_keys($months)));
            if ($missing) {
                $groups[implode(',', $missing)][] = (string) $concept;
            }
        }
        foreach ($groups as $set => $concepts) {
            $missing = array_map('intval', explode(',', (string) $set));
            $first = $concepts[0];
            $names = array_map(static fn($c) => '«' . ($labels[$c]['label'] ?? $c) . '»', $concepts);
            $who = count($names) === 1 ? $names[0]
                : sprintf('%d conceptos (%s%s)', count($names), implode(', ', array_slice($names, 0, 4)), count($names) > 4 ? ', …' : '');
            $this->issue('FMT_DATE', ['sheet' => $name, 'row' => $labels[$first]['row'] ?? null,
                'concept' => count($concepts) === 1 ? $first : null,
                'month' => count($missing) === 1 ? $missing[0] : null,
                'message' => sprintf('En «%s» faltan los datos de %s para %s.', $name,
                    implode(', ', array_map([self::class, 'monthLabel'], $missing)), $who)]);
        }
        return ['rows' => $out, 'unmapped' => $unmapped];
    }

    /** Aplica escala a un monto (no a volúmenes ni precios) y el valor absoluto a 1502. */
    private function scaleValue(?float $value, string $concept, mixed $unit, array $src): ?float
    {
        if ($value === null) {
            return null;
        }
        if (self::isStat($concept)) {
            return $value;
        }
        $f = $this->factor;
        $uf = $this->unitFactor($unit);
        if ($uf !== null) {
            if ($uf != $this->factor) {
                $k = $src['sheet'] . '|' . self::norm((string) $unit);
                $this->scaleIssues[$k] ??= ['sheet' => $src['sheet'], 'row' => $src['row'], 'col' => $src['col'], 'unit' => trim((string) $unit), 'n' => 0, 'factor' => $uf];
                $this->scaleIssues[$k]['n']++;
            }
            $f = $uf;
        }
        $value = self::scaled($value, $f);
        if ($concept === '1502') {
            $value = abs($value);
        }
        return $value;
    }

    private function duplicate(string $concept, string $label, int $m, array $first, array $src, ?float $value): void
    {
        $this->issue('DATA_DUP', $src + ['month' => $m, 'concept' => $concept,
            'actual' => $value, 'expected' => $first['value'],
            'message' => sprintf('«%s» (%s) aparece dos veces para %s: %s!%s%d = %s y %s!%s%d = %s. Se tomó el primero.',
                $label, $concept, self::monthLabel($m),
                $first['src']['sheet'], $first['src']['col'], $first['src']['row'], self::fmt($first['value']),
                $src['sheet'], $src['col'], $src['row'], self::fmt($value))]);
    }

    private function emptyCell(mixed $raw, array $src, ?int $m, ?string $concept, string $label): void
    {
        $what = $raw === null || (is_string($raw) && trim($raw) === '')
            ? 'está vacía'
            : 'tiene «' . trim((string) $raw) . '», que no es un número';
        $this->issue('DATA_EMPTY', $src + ['month' => $m, 'concept' => $concept,
            'message' => sprintf('La celda %s!%s%d («%s»%s) %s; falta el valor.', $src['sheet'], $src['col'], $src['row'],
                $label, $m === null ? '' : ', ' . self::monthLabel($m), $what)]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // ventas_detalle, intercompania, inventario_grupo, capex, indicadores_reportados
    // ------------------------------------------------------------------------------------------------------------

    private function readSales(?array $sheet): array
    {
        if ($sheet === null) {
            return [];
        }
        $cfg  = $this->profile['sheets']['ventas_detalle'] ?? [];
        $cols = $this->columns($cfg, ['period' => 'A', 'product' => 'C', 'channel' => 'E', 'volume' => 'F', 'amount' => 'G']);
        $name = $sheet['name'];
        $header = (int) ($cfg['header_row'] ?? 1);
        $amountFactor = $this->headerFactor($sheet, $header, $cols['amount'] ?? null);
        $products = $this->corporate['products'] ?? [];
        $channels = $this->corporate['channels'] ?? [];

        $out = [];
        $seen = [];
        $monthsSeen = [];
        foreach ($sheet['rows'] as $r => $cells) {
            if ($r <= $header) {
                continue;
            }
            $r = (int) $r;
            $m = $this->rowMonth($cells, $cols, $name, $r, false);
            if ($m === null) {
                continue;
            }
            $monthsSeen[$m] = true;

            // Producto: código del maestro; si no, nombre (ES/EN) del mapa del perfil.
            $prodRaw = isset($cols['product']) ? trim((string) ($cells[$cols['product']] ?? '')) : '';
            $nameRaw = isset($cols['product_name']) ? trim((string) ($cells[$cols['product_name']] ?? '')) : '';
            $product = strtoupper($prodRaw);
            if (!isset($products[$product])) {
                $byCode = $this->maps['product'][self::norm($prodRaw)] ?? null;
                $byName = $nameRaw !== '' ? ($this->maps['product'][self::norm($nameRaw)] ?? null) : null;
                if ($byCode !== null && isset($products[$byCode])) {
                    $product = $byCode; // el archivo trae el nombre (o un alias del perfil) en vez del código
                } elseif ($prodRaw === '' && $byName !== null && isset($products[$byName])) {
                    $product = $byName;
                } else {
                    $this->issue('CAT_PRODUCT', ['sheet' => $name, 'row' => $r, 'col' => self::colLetter($cols['product'] ?? 0), 'month' => $m,
                        'message' => sprintf('El producto «%s»%s de %s!%s%d no está en el maestro de productos del grupo.',
                            $prodRaw, $nameRaw !== '' ? ' (' . $nameRaw . ')' : '', $name, self::colLetter($cols['product'] ?? 0), $r)]);
                    $product = $prodRaw === '' ? null : $prodRaw;
                }
            }

            // Línea: la del maestro manda; si el archivo dice otra, se avisa.
            $lineRaw = isset($cols['line']) ? trim((string) ($cells[$cols['line']] ?? '')) : '';
            $lineFile = $lineRaw === '' ? null : ($this->maps['line'][self::norm($lineRaw)] ?? null);
            $line = $product !== null && isset($products[$product]) ? $products[$product]['line'] : $lineFile;
            if ($lineRaw !== '' && $lineFile === null) {
                $this->issue('CAT_PRODUCT', ['sheet' => $name, 'row' => $r, 'col' => self::colLetter($cols['line']), 'month' => $m,
                    'message' => sprintf('La línea «%s» de %s!%s%d no es una línea del grupo (Snacks, Bebidas, Confitería).', $lineRaw, $name, self::colLetter($cols['line']), $r)]);
            } elseif ($lineFile !== null && $line !== null && $lineFile !== $line) {
                $this->issue('CAT_PRODUCT', ['sheet' => $name, 'row' => $r, 'col' => self::colLetter($cols['line']), 'month' => $m,
                    'message' => sprintf('En %s!%s%d el producto %s está en la línea «%s», pero en el maestro es de %s.', $name, self::colLetter($cols['line']), $r, $product, $lineRaw, $line)]);
            }

            $chRaw = isset($cols['channel']) ? trim((string) ($cells[$cols['channel']] ?? '')) : '';
            $channel = $this->maps['channel'][self::norm($chRaw)] ?? null;
            if ($channel === null && isset($channels[strtoupper($chRaw)])) {
                $channel = strtoupper($chRaw);
            }
            if ($channel === null || !isset($channels[$channel])) {
                $this->issue('CAT_CHANNEL', ['sheet' => $name, 'row' => $r, 'col' => self::colLetter($cols['channel'] ?? 0), 'month' => $m,
                    'message' => sprintf('El canal «%s» de %s!%s%d no está en el maestro de canales (C1 a C4).', $chRaw, $name, self::colLetter($cols['channel'] ?? 0), $r)]);
                $channel = $chRaw === '' ? null : $chRaw;
            }

            $volLetter = self::colLetter($cols['volume']);
            $amtLetter = self::colLetter($cols['amount']);
            $volRaw = $cells[$cols['volume']] ?? null;
            $amtRaw = $cells[$cols['amount']] ?? null;
            $volume = $this->number($volRaw, $name, $r, $volLetter);
            $amount = $this->number($amtRaw, $name, $r, $amtLetter);
            $label = trim(($product ?? '?') . ' / ' . ($channel ?? '?'));
            if ($volume === null) {
                $this->emptyCell($volRaw, ['sheet' => $name, 'row' => $r, 'col' => $volLetter], $m, null, $label . ' volumen');
            }
            if ($amount === null) {
                $this->emptyCell($amtRaw, ['sheet' => $name, 'row' => $r, 'col' => $amtLetter], $m, null, $label . ' ventas');
            }
            $amount = $amount === null ? null : self::scaled($amount, $amountFactor);

            $src = ['sheet' => $name, 'row' => $r, 'col' => $amtLetter];
            $key = $m . '|' . $product . '|' . $channel;
            if (isset($seen[$key])) {
                $this->issue('DATA_DUP', $src + ['month' => $m, 'actual' => $amount, 'expected' => $seen[$key]['amount'],
                    'message' => sprintf('%s en el canal %s aparece dos veces para %s (filas %d y %d de «%s»). Se tomó la primera.',
                        $product, $channel, self::monthLabel($m), $seen[$key]['row'], $r, $name)]);
                continue;
            }
            $seen[$key] = ['row' => $r, 'amount' => $amount];
            $out[] = ['month' => $m, 'line' => $line, 'product' => $product, 'channel' => $channel,
                'volume' => $volume, 'amount' => $amount, 'src' => $src];
        }
        if ($out) {
            foreach ($this->expectedMonths(false) as $m) {
                if (!isset($monthsSeen[$m])) {
                    $this->issue('FMT_DATE', ['sheet' => $name, 'month' => $m,
                        'message' => sprintf('«%s» no tiene filas para %s.', $name, self::monthLabel($m))]);
                }
            }
        }
        return $out;
    }

    private function readIntercompany(?array $sheet): array
    {
        if ($sheet === null) {
            return [];
        }
        $cfg  = $this->profile['sheets']['intercompania'] ?? [];
        $cols = $this->columns($cfg, ['period' => 'A', 'counterparty' => 'B', 'type' => 'C', 'line' => 'D', 'direction' => 'E', 'amount' => 'F', 'currency' => 'G', 'note' => 'H']);
        $name = $sheet['name'];
        $header = (int) ($cfg['header_row'] ?? 1);
        $out = [];
        $seen = [];
        foreach ($sheet['rows'] as $r => $cells) {
            if ($r <= $header) {
                continue;
            }
            $r = (int) $r;
            $m = $this->rowMonth($cells, $cols, $name, $r, false);
            if ($m === null) {
                continue;
            }
            $cp   = $this->mapped('counterparty', $cells, $cols, $name, $r, $m, 'La contraparte');
            $type = $this->mapped('ic_type', $cells, $cols, $name, $r, $m, 'El tipo de operación intercompañía');
            $dir  = $this->mapped('direction', $cells, $cols, $name, $r, $m, 'El sentido');
            $line = null;
            $lineRaw = isset($cols['line']) ? trim((string) ($cells[$cols['line']] ?? '')) : '';
            if ($lineRaw !== '') {
                $line = $this->maps['line'][self::norm($lineRaw)] ?? null;
                if ($line === null) {
                    $this->issue('CAT_UNKNOWN', ['sheet' => $name, 'row' => $r, 'col' => self::colLetter($cols['line']), 'month' => $m,
                        'message' => sprintf('La línea «%s» de %s!%s%d no es una línea del grupo.', $lineRaw, $name, self::colLetter($cols['line']), $r)]);
                }
            }
            $amtLetter = self::colLetter($cols['amount']);
            $amtRaw = $cells[$cols['amount']] ?? null;
            $amount = $this->number($amtRaw, $name, $r, $amtLetter);
            if ($amount === null) {
                $this->emptyCell($amtRaw, ['sheet' => $name, 'row' => $r, 'col' => $amtLetter], $m, null, 'monto intercompañía');
            }
            $cur = isset($cols['currency']) ? strtoupper(trim((string) ($cells[$cols['currency']] ?? ''))) : '';
            if ($cur === '') {
                $this->issue('DATA_EMPTY', ['sheet' => $name, 'row' => $r, 'col' => self::colLetter($cols['currency'] ?? 6), 'month' => $m,
                    'message' => sprintf('Falta la moneda pactada en %s!%s%d; se asumió USD.', $name, self::colLetter($cols['currency'] ?? 6), $r)]);
                $cur = 'USD';
            }
            $note = isset($cols['note']) ? trim((string) ($cells[$cols['note']] ?? '')) : '';
            // Fila idéntica a otra (mismo mes, contraparte, tipo, línea, sentido, monto, moneda y nota): se reporta
            // DATA_DUP y se conserva la primera. Dos operaciones distintas del mismo tipo en el mes sí se aceptan.
            $key = implode('|', [$m, $cp, $type, $line, $dir, $amount === null ? '' : round($amount, 2), $cur, $note]);
            if (isset($seen[$key])) {
                $this->issue('DATA_DUP', ['sheet' => $name, 'row' => $r, 'col' => $amtLetter, 'month' => $m, 'actual' => $amount,
                    'message' => sprintf('La operación intercompañía de la fila %d («%s», %s, %s %s) repite exactamente la fila %d de «%s»; se tomó solo una.',
                        $r, $cells[$cols['type']] ?? '', $cp ?? '?', self::fmt($amount), $cur, $seen[$key], $name)]);
                continue;
            }
            $seen[$key] = $r;
            $out[] = [
                'month' => $m, 'counterparty' => $cp, 'type' => $type, 'line' => $line, 'direction' => $dir,
                'amount' => $amount, 'currency' => $cur, 'note' => $note === '' ? null : $note,
                'src' => ['sheet' => $name, 'row' => $r, 'col' => $amtLetter],
            ];
        }
        return $out;
    }

    private function readInventory(?array $sheet): array
    {
        if ($sheet === null) {
            return [];
        }
        $cfg  = $this->profile['sheets']['inventario_grupo'] ?? [];
        $cols = $this->columns($cfg, ['period' => 'A', 'supplier' => 'B', 'line' => 'C', 'amount' => 'D']);
        $name = $sheet['name'];
        $header = (int) ($cfg['header_row'] ?? 1);
        $factor = $this->headerFactor($sheet, $header, $cols['amount']);
        $out = [];
        $seen = [];
        foreach ($sheet['rows'] as $r => $cells) {
            if ($r <= $header) {
                continue;
            }
            $r = (int) $r;
            $m = $this->rowMonth($cells, $cols, $name, $r, true);
            if ($m === null) {
                continue;
            }
            $supplier = $this->mapped('counterparty', $cells, $cols, $name, $r, $m, 'El proveedor', 'supplier');
            $lineRaw = trim((string) ($cells[$cols['line']] ?? ''));
            $line = $this->maps['line'][self::norm($lineRaw)] ?? null;
            if ($line === null) {
                $this->issue('CAT_UNKNOWN', ['sheet' => $name, 'row' => $r, 'col' => self::colLetter($cols['line']), 'month' => $m,
                    'message' => sprintf('La línea «%s» de %s!%s%d no es una línea del grupo.', $lineRaw, $name, self::colLetter($cols['line']), $r)]);
            }
            $letter = self::colLetter($cols['amount']);
            $raw = $cells[$cols['amount']] ?? null;
            $amount = $this->number($raw, $name, $r, $letter);
            $src = ['sheet' => $name, 'row' => $r, 'col' => $letter];
            if ($amount === null) {
                $this->emptyCell($raw, $src, $m, null, 'inventario de ' . ($supplier ?? '?') . ' ' . ($line ?? ''));
            }
            $amount = $amount === null ? null : self::scaled($amount, $factor);
            $key = $m . '|' . $supplier . '|' . $line;
            if (isset($seen[$key])) {
                $this->issue('DATA_DUP', $src + ['month' => $m, 'actual' => $amount, 'expected' => $seen[$key]['amount'],
                    'message' => sprintf('El inventario de %s (%s) aparece dos veces para %s (filas %d y %d de «%s»). Se tomó el primero.',
                        $supplier, $line, self::monthLabel($m), $seen[$key]['row'], $r, $name)]);
                continue;
            }
            $seen[$key] = ['row' => $r, 'amount' => $amount];
            $out[] = ['month' => $m, 'supplier' => $supplier, 'line' => $line, 'amount' => $amount, 'src' => $src];
        }
        return $out;
    }

    private function readCapex(?array $sheet): array
    {
        if ($sheet === null) {
            return [];
        }
        $cfg  = $this->profile['sheets']['capex'] ?? [];
        $cols = $this->columns($cfg, ['period' => 'A', 'project' => 'B', 'description' => 'C', 'amount' => 'D']);
        $name = $sheet['name'];
        $header = (int) ($cfg['header_row'] ?? 1);
        $factor = $this->headerFactor($sheet, $header, $cols['amount']);
        $out = [];
        foreach ($sheet['rows'] as $r => $cells) {
            if ($r <= $header) {
                continue;
            }
            $r = (int) $r;
            $m = $this->rowMonth($cells, $cols, $name, $r, false);
            if ($m === null) {
                continue;
            }
            $letter = self::colLetter($cols['amount']);
            $raw = $cells[$cols['amount']] ?? null;
            $amount = $this->number($raw, $name, $r, $letter);
            $src = ['sheet' => $name, 'row' => $r, 'col' => $letter];
            $project = isset($cols['project']) ? trim((string) ($cells[$cols['project']] ?? '')) : '';
            if ($amount === null) {
                $this->emptyCell($raw, $src, $m, null, 'capex ' . $project);
            }
            $desc = isset($cols['description']) ? trim((string) ($cells[$cols['description']] ?? '')) : '';
            $out[] = ['month' => $m, 'project' => $project === '' ? null : $project, 'description' => $desc === '' ? null : $desc,
                'amount' => $amount === null ? null : self::scaled($amount, $factor), 'src' => $src];
        }
        return $out;
    }

    /**
     * indicadores_reportados → reported (§6). Montos a unidades según la columna Unidad («miles MXN», «USD 000»);
     * %, tasas y tipos de cambio sin escala. tax_rate como fracción (25 → 0.25, igual que companies.tax); los campos
     * *_pct quedan en puntos porcentuales, como los escribe el país.
     */
    private function readReported(?array $sheet): ?array
    {
        if ($sheet === null) {
            return null;
        }
        $cfg  = $this->profile['sheets']['indicadores_reportados'] ?? [];
        $cols = $this->columns($cfg, ['label' => 'A', 'value' => 'B', 'unit' => 'C']);
        $name = $sheet['name'];
        $header = (int) ($cfg['header_row'] ?? 1);
        $out = [];
        $rowsSrc = [];
        foreach ($sheet['rows'] as $r => $cells) {
            if ($r <= $header) {
                continue;
            }
            $r = (int) $r;
            $label = trim((string) ($cells[$cols['label']] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = $this->maps['reported'][self::norm($label)] ?? null;
            if ($key === null) {
                $this->issue('CAT_UNKNOWN', ['sheet' => $name, 'row' => $r, 'col' => self::colLetter($cols['label']),
                    'message' => sprintf('El indicador «%s» de %s!%s%d no se reconoce.', $label, $name, self::colLetter($cols['label']), $r)]);
                continue;
            }
            $letter = self::colLetter($cols['value']);
            $raw = $cells[$cols['value']] ?? null;
            $v = $this->number($raw, $name, $r, $letter);
            $src = ['sheet' => $name, 'row' => $r, 'col' => $letter];
            if ($v === null) {
                $this->emptyCell($raw, $src, null, null, $label);
            } elseif (in_array($key, self::REPORTED_AMOUNTS, true)) {
                $unit = isset($cols['unit']) ? ($cells[$cols['unit']] ?? null) : null;
                $f = $this->unitFactor($unit);
                $v = self::scaled($v, $f ?? ($key === 'eva_usd' ? 1.0 : $this->factor));
            } elseif ($key === 'tax_rate') {
                $v = abs($v) > 1.0 ? $v / 100 : $v;
            }
            if (array_key_exists($key, $out)) {
                $this->issue('DATA_DUP', $src + ['actual' => $v, 'expected' => $out[$key],
                    'message' => sprintf('El indicador «%s» aparece dos veces en «%s»; se tomó el primero.', $label, $name)]);
                continue;
            }
            $out[$key] = $v;
            $rowsSrc[$key] = $src;
        }
        if (!$out) {
            return null;
        }
        $full = [];
        foreach (array_unique(array_values($this->profile['maps']['reported'] ?? [])) as $k) {
            $full[$k] = $out[$k] ?? null;
        }
        $full['rows'] = $rowsSrc;
        return $full;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Piezas de lectura
    // ------------------------------------------------------------------------------------------------------------

    /** Columnas del perfil (letras) → índices base 0. */
    private function columns(array $cfg, array $defaults): array
    {
        $out = [];
        foreach (array_merge($defaults, (array) ($cfg['columns'] ?? [])) as $field => $letter) {
            if ($letter === null || $letter === '') {
                continue;
            }
            $out[$field] = is_int($letter) ? $letter : Reader::columnIndex((string) $letter);
        }
        return $out;
    }

    /**
     * Mes de una fila de tabla. Filas de nota sin fecha (p. ej. «Sin inventario comprado a empresas del grupo») se
     * saltan sin issue; una fecha no reconocida en una fila con datos es FMT_DATE.
     */
    private function rowMonth(array $cells, array $cols, string $sheet, int $r, bool $isStock): ?int
    {
        $raw = $cells[$cols['period']] ?? null;
        $others = 0;
        foreach ($cols as $field => $c) {
            if ($field !== 'period' && isset($cells[$c]) && $cells[$c] !== '') {
                $others++;
            }
        }
        if ($others === 0) {
            return null; // fila de nota o vacía
        }
        $letter = self::colLetter($cols['period']);
        $p = $this->parsePeriod($raw);
        if ($p === null) {
            $this->issue('FMT_DATE', ['sheet' => $sheet, 'row' => $r, 'col' => $letter,
                'message' => sprintf('El periodo «%s» de %s!%s%d no se reconoce como fecha; la fila no se leyó.', trim((string) $raw), $sheet, $letter, $r)]);
            return null;
        }
        return $this->resolveMonth($p, $isStock, $sheet, $r, $letter);
    }

    /** Valor de una columna traducido con un mapa del perfil; si no está, CAT_UNKNOWN y null. */
    private function mapped(string $mapName, array $cells, array $cols, string $sheet, int $r, int $m, string $what, ?string $field = null): ?string
    {
        $field ??= match ($mapName) {
            'ic_type' => 'type',
            'direction' => 'direction',
            default => $mapName,
        };
        $map = $mapName === 'direction' ? ($this->maps['ic_direction'] ?? []) : ($this->maps[$mapName] ?? []);
        if (!isset($cols[$field])) {
            return null;
        }
        $raw = trim((string) ($cells[$cols[$field]] ?? ''));
        $val = $map[self::norm($raw)] ?? null;
        if ($val === null && $mapName === 'counterparty') {
            // Respaldo: nombre o código de la empresa en parametros_paises.
            foreach ($this->corporate['companies'] ?? [] as $code => $c) {
                if (self::norm($raw) === self::norm((string) $code) || self::norm($raw) === self::norm((string) ($c['name'] ?? ''))) {
                    $val = (string) $code;
                    break;
                }
            }
        }
        if ($val === null) {
            $letter = self::colLetter($cols[$field]);
            $this->issue('CAT_UNKNOWN', ['sheet' => $sheet, 'row' => $r, 'col' => $letter, 'month' => $m,
                'message' => sprintf('%s «%s» de %s!%s%d no se reconoce.', $what, $raw, $sheet, $letter, $r)]);
        }
        return $val === null ? null : (string) $val;
    }

    /**
     * Convierte el contenido de una celda en número. Acepta números de Excel y texto «1,918.676», «(1,000)»,
     * «-16,540.923», «1.234,5» (si el perfil usa coma decimal), «$ 1,000», «25 %». Cuenta los números guardados como
     * texto para FMT_TEXT_NUMBER. Devuelve null si la celda está vacía o no es un número.
     */
    private function number(mixed $v, string $sheet, int $row, string $col): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if ($v === null || is_bool($v)) {
            return null;
        }
        $n = self::parseNumber((string) $v, (string) ($this->profile['decimal'] ?? '.'));
        if ($n !== null) {
            $this->textNumbers[$sheet] ??= ['n' => 0, 'row' => $row, 'col' => $col, 'raw' => trim((string) $v)];
            $this->textNumbers[$sheet]['n']++;
        }
        return $n;
    }

    /** Número escrito como texto → float; null si no es un número. */
    public static function parseNumber(string $s, string $decimal = '.'): ?float
    {
        $t = trim(str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", ' '], '', $s));
        if ($t === '' || in_array($t, ['-', '–', '—'], true)) {
            return null;
        }
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $t, $m)) {
            $neg = true;
            $t = $m[1];
        }
        if (str_ends_with($t, '-')) {
            $neg = !$neg;
            $t = substr($t, 0, -1);
        }
        $t = preg_replace('/^(US\$|\$|USD|GTQ|MXN|Q)/i', '', $t) ?? $t;
        $t = rtrim($t, '%');
        if (str_starts_with($t, '−')) {
            $t = '-' . substr($t, strlen('−'));
        }
        if ($decimal === ',') {
            $t = str_replace('.', '', $t);
            $t = str_replace(',', '.', $t);
        } else {
            if (!preg_match('/^[+-]?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?([eE][+-]?\d+)?$/', $t)) {
                return null;
            }
            $t = str_replace(',', '', $t);
        }
        if (!is_numeric($t)) {
            return null;
        }
        $n = (float) $t;
        return $neg ? -$n : $n;
    }

    /**
     * Interpreta un periodo. Devuelve ['y'=>?int,'m'=>?int,'opening'=>bool,'raw'=>string,'swapped'=>bool] o null.
     */
    private function parsePeriod(mixed $v): ?array
    {
        if ($v === null || is_bool($v)) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            $n = (int) $v;
            if ($n > 20000 && $n < 80000) { // serial de fecha de Excel
                $ts = ($n - 25569) * 86400;
                return ['y' => (int) gmdate('Y', $ts), 'm' => (int) gmdate('n', $ts), 'opening' => false, 'raw' => gmdate('Y-m-d', $ts), 'swapped' => false];
            }
            if ($n >= 190001 && $n <= 210012 && $n % 100 >= 1 && $n % 100 <= 12) { // 202701
                return ['y' => intdiv($n, 100), 'm' => $n % 100, 'opening' => false, 'raw' => (string) $n, 'swapped' => false];
            }
            return null;
        }
        $raw = trim((string) $v);
        if ($raw === '') {
            return null;
        }
        $t = self::norm($raw);

        // «Saldo inicial», «Opening Balance», «Opening 12/31/2026».
        foreach (array_keys($this->maps['opening'] ?? []) as $alias) {
            if ($alias !== '' && ($t === $alias || str_starts_with($t, $alias . ' '))) {
                $rest = trim(substr($t, strlen($alias)));
                $d = $rest === '' ? null : $this->parsePeriod($rest);
                return ['y' => $d['y'] ?? null, 'm' => $d['m'] ?? null, 'opening' => true, 'raw' => $raw, 'swapped' => $d['swapped'] ?? false];
            }
        }
        // aaaa-mm o aaaa-mm-dd
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})(?:[-\/.](\d{1,2}))?(?:[ t].*)?$/', $t, $m)) {
            $mo = (int) $m[2];
            return $mo >= 1 && $mo <= 12 ? ['y' => (int) $m[1], 'm' => $mo, 'opening' => false, 'raw' => $raw, 'swapped' => false] : null;
        }
        // dd/mm/aaaa o mm/dd/aaaa
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2}|\d{4})$/', $t, $m)) {
            $y = (int) $m[3];
            if ($y < 100) {
                $y += 2000;
            }
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            $mdy = ($this->profile['date_order'] ?? 'dmy') === 'mdy';
            [$mo, $d] = $mdy ? [$a, $b] : [$b, $a];
            if (checkdate($mo, $d, $y)) {
                return ['y' => $y, 'm' => $mo, 'opening' => false, 'raw' => $raw, 'swapped' => false];
            }
            if (checkdate($d, $mo, $y)) {
                return ['y' => $y, 'm' => $d, 'opening' => false, 'raw' => $raw, 'swapped' => true];
            }
            return null;
        }
        // «Ene», «Ene-2027», «Jan-2027», «January 2027», «Sep-27»
        if (preg_match('/^([a-z]+)\.?(?:[\s\-\/]*(\d{4}|\d{2}))?$/', $t, $m) && isset(self::MONTH_NAMES[$m[1]])) {
            $y = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            if ($y !== null && $y < 100) {
                $y += 2000;
            }
            return ['y' => $y, 'm' => self::MONTH_NAMES[$m[1]], 'opening' => false, 'raw' => $raw, 'swapped' => false];
        }
        return null;
    }

    private function isOpening(string $s): bool
    {
        $t = self::norm($s);
        foreach (array_keys($this->maps['opening'] ?? []) as $alias) {
            if ($t === $alias || str_starts_with($t, $alias . ' ')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mes (0..12) de un periodo ya interpretado, con las reglas de año de la versión. Registra FMT_DATE (agregado por
     * hoja y texto) cuando la fecha está fuera del periodo o viene en otro formato.
     */
    private function resolveMonth(array $p, bool $isStock, string $sheet, int $row, string $col): ?int
    {
        $Y = $this->year;
        if ($p['opening']) {
            if (!$isStock) {
                $this->dateIssue($sheet, $p['raw'], $row, $col, null, sprintf('«%s» es un saldo inicial dentro de una hoja de movimientos del año; la fila no se leyó.', $p['raw']));
                return null;
            }
            if ($p['y'] !== null && $Y !== null && !($p['y'] === $Y - 1 && $p['m'] === 12)) {
                $this->dateIssue($sheet, $p['raw'], $row, $col, 0, sprintf('El saldo inicial dice «%s», pero para %d debe ser al 31/12/%d. Se tomó como saldo inicial.', $p['raw'], $Y, $Y - 1));
            }
            return 0;
        }
        $y = $p['y'] ?? $Y;
        $m = (int) $p['m'];
        if ($p['swapped']) {
            $this->dateIssue($sheet, $p['raw'], $row, $col, $m, sprintf('La fecha «%s» no sigue el formato del país (%s); se leyó como %s de %d.',
                $p['raw'], ($this->profile['date_order'] ?? 'dmy') === 'mdy' ? 'mm/dd/aaaa' : 'dd/mm/aaaa', self::monthLabel($m), $y));
        }
        if ($Y === null || $y === $Y) {
            return $m;
        }
        if ($isStock && $y === $Y - 1 && $m === 12) {
            return 0;
        }
        $this->dateIssue($sheet, $p['raw'], $row, $col, $m, sprintf('La fecha «%s» está fuera del año %d de la versión %s; se tomó como %s de %d.',
            $p['raw'], $Y, $this->version, self::monthLabel($m), $Y));
        return $m;
    }

    private function dateIssue(string $sheet, string $raw, int $row, string $col, ?int $m, string $message): void
    {
        $k = $sheet . '|' . $raw;
        $this->dateIssues[$k] ??= ['sheet' => $sheet, 'row' => $row, 'col' => $col, 'month' => $m, 'message' => $message, 'n' => 0];
        $this->dateIssues[$k]['n']++;
    }

    /** Meses que debe tener una hoja: 1..12 (movimientos) o 0..12 (saldos). */
    private function expectedMonths(bool $isStock): array
    {
        return $isStock ? range(0, 12) : range(1, 12);
    }

    /**
     * Factor de escala de una columna de montos según su encabezado: «Ventas (miles MXN)» → 1000, «Sales (USD)» → 1;
     * sin indicación → la escala de la portada. Si el encabezado contradice la portada se reporta FMT_SCALE_DECL.
     */
    private function headerFactor(array $sheet, int $headerRow, ?int $col): float
    {
        if ($col === null) {
            return $this->factor;
        }
        $text = $sheet['rows'][$headerRow][$col] ?? null;
        $f = is_string($text) && preg_match('/\(([^)]*)\)/', $text, $m) ? $this->unitFactor($m[1]) : null;
        if ($f === null) {
            return $this->factor;
        }
        if ($f != $this->factor) {
            $letter = self::colLetter($col);
            $this->issue('FMT_SCALE_DECL', ['sheet' => $sheet['name'], 'row' => $headerRow, 'col' => $letter,
                'message' => sprintf('El encabezado «%s» de «%s» indica montos ×%s, distinto de la escala de la portada (%s). Se usó la del encabezado.',
                    trim($text), $sheet['name'], self::fmt($f, 0), $this->scaleKey)]);
        }
        return $f;
    }

    /**
     * Factor de una unidad escrita: «miles MXN», «USD 000» → 1000; «MXN», «USD (units)», «GTQ (unidades)» → 1;
     * cajas, precios por caja, %, tipos de cambio → null (no es un monto).
     */
    private function unitFactor(mixed $unit): ?float
    {
        if ($unit === null || (is_string($unit) && trim($unit) === '')) {
            return null;
        }
        $t = self::norm((string) $unit);
        if (str_contains($t, '/') || str_contains($t, '%') || preg_match('/\b(cajas?|cases?|boxes|pct|porcentaje|percent)\b/', $t)) {
            return null;
        }
        if (preg_match('/\b(millones|millions?|mm)\b/', $t)) {
            return 1000000.0;
        }
        if (preg_match('/\b(miles|thousands?|000|k)\b/', $t)) {
            return 1000.0;
        }
        if (preg_match('/\b(usd|mxn|gtq|eur|unidades|units?)\b|^\$|^q$/', $t)) {
            return 1.0;
        }
        return null;
    }

    private static function scaleFactor(string $key): float
    {
        return match ($key) {
            'miles' => 1000.0,
            'millones' => 1000000.0,
            default => 1.0,
        };
    }

    /** Monto × escala, redondeado a 6 decimales para no arrastrar ruido de coma flotante (2,080.11 × 1000). */
    private static function scaled(float $v, float $f): float
    {
        return $f == 1.0 ? $v : round($v * $f, 6);
    }

    private static function isStat(string $concept): bool
    {
        return str_starts_with($concept, 'volume:') || str_starts_with($concept, 'price:');
    }

    private function isoDate(mixed $v): ?string
    {
        return Corporate::isoDate($v, (string) ($this->profile['date_order'] ?? 'dmy'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Issues
    // ------------------------------------------------------------------------------------------------------------

    private function issue(string $code, array $f): void
    {
        [$severity, $category] = self::ISSUE_META[$code] ?? ['critico', 'catalogo_periodo'];
        $this->issues[] = [
            'code'       => $code,
            'severity'   => $f['severity'] ?? $severity,
            'category'   => $category,
            'company'    => $this->company,
            'version'    => $this->version,
            'sheet'      => $f['sheet'] ?? null,
            'row'        => isset($f['row']) ? (int) $f['row'] : null,
            'col'        => $f['col'] ?? null,
            'month'      => $f['month'] ?? null,
            'concept'    => isset($f['concept']) ? (string) $f['concept'] : null,
            'message'    => $f['message'] ?? '',
            'actual'     => isset($f['actual']) ? (float) $f['actual'] : null,
            'expected'   => isset($f['expected']) ? (float) $f['expected'] : null,
            'impact_usd' => null,
        ];
    }

    /** Emite los issues agregados (fechas, etiquetas desconocidas en formato largo, escala por fila, texto). */
    private function flush(): void
    {
        foreach ($this->dateIssues as $d) {
            $msg = $d['message'];
            if ($d['n'] > 1) {
                $msg .= sprintf(' (%s filas con esa fecha en «%s»; primera en %s%d).', self::fmt($d['n'], 0), $d['sheet'], $d['col'], $d['row']);
            }
            $this->issue('FMT_DATE', ['sheet' => $d['sheet'], 'row' => $d['row'], 'col' => $d['col'], 'month' => $d['month'], 'message' => $msg]);
        }
        foreach ($this->unknown as $u) {
            $this->issue('CAT_UNKNOWN', ['sheet' => $u['sheet'], 'row' => $u['row'], 'col' => $u['col'], 'actual' => $u['total'],
                'message' => sprintf('La cuenta «%s» (%s!%s%d, %s %s) no está en el catálogo del grupo ni en el perfil de lectura de %s; no se sumó. %s %s.',
                    $u['label'], $u['sheet'], $u['col'], $u['row'], self::fmt($u['n'], 0), $u['n'] === 1 ? 'fila' : 'filas', $this->company,
                    $u['stock'] ? 'Último saldo:' : 'Total del año:', self::fmt($u['total']))]);
        }
        foreach ($this->scaleIssues as $s) {
            $this->issue('FMT_SCALE_DECL', ['sheet' => $s['sheet'], 'row' => $s['row'], 'col' => $s['col'],
                'message' => sprintf('%s %s de «%s» %s unidad «%s» (×%s), distinta de la escala de la portada (%s). Se usó la unidad de cada fila.',
                    self::fmt($s['n'], 0), $s['n'] === 1 ? 'fila' : 'filas', $s['sheet'], $s['n'] === 1 ? 'dice' : 'dicen',
                    $s['unit'], self::fmt($s['factor'], 0), $this->scaleKey)]);
        }
        if ($this->textNumbers) {
            $total = array_sum(array_column($this->textNumbers, 'n'));
            $first = reset($this->textNumbers);
            $sheet = (string) key($this->textNumbers);
            $parts = [];
            foreach ($this->textNumbers as $sh => $t) {
                $parts[] = $sh . ' (' . self::fmt($t['n'], 0) . ')';
            }
            $this->issue('FMT_TEXT_NUMBER', ['sheet' => $sheet, 'row' => $first['row'], 'col' => $first['col'],
                'message' => sprintf('%s como texto (p. ej. %s!%s%d = «%s»); se leyeron igual. Hojas: %s.',
                    $total === 1 ? '1 número viene guardado' : self::fmt($total, 0) . ' números vienen guardados', $sheet, $first['col'], $first['row'], $first['raw'], implode(', ', $parts))]);
        }
        $this->dateIssues = $this->unknown = $this->scaleIssues = $this->textNumbers = [];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Utilidades públicas
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Normaliza una etiqueta para compararla: minúsculas, sin acentos, guiones y apóstrofes unificados, espacios
     * simples.
     */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            '–' => '-', '—' => '-', '’' => "'", '‘' => "'", "\u{00A0}" => ' ',
        ]);
        return (string) preg_replace('/\s+/', ' ', $s);
    }

    /** Número con formato es-GT: 1,234,567.89. */
    public static function fmt(?float $n, int $decimals = 2): string
    {
        if ($n === null) {
            return '(vacío)';
        }
        return number_format($n, $decimals, '.', ',');
    }

    public static function monthLabel(int $m): string
    {
        return self::MONTH_LABELS[$m] ?? (string) $m;
    }

    /** Índice de columna base 0 → letra de Excel (0 → A, 27 → AB). */
    public static function colLetter(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $r = ($i - 1) % 26;
            $s = chr(65 + $r) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    }
}
