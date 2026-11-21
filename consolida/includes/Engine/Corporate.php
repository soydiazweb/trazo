<?php
/**
 * Lector del archivo corporativo del grupo (Grupo_Corporativo.xlsx) → estructura Corporate (contrato §4).
 *
 *   $corp = Corporate::load('/ruta/Grupo_Corporativo.xlsx');
 *   $corp['companies']['GT']['wacc'];                 // 0.11
 *   $corp['rates']['ppto_2027_v1']['GTQ'][1]['avg'];  // 7.6837
 *   Corporate::openingRate($corp, 'ppto_2027_v1', 'GTQ');
 *
 * Todas las hojas traen el encabezado en la fila 1; las columnas se buscan por nombre (no por posición), así que el
 * orden de las columnas puede cambiar. Fechas dd/mm/aaaa → ISO aaaa-mm-dd. Tasas de impuesto, WACC y participaciones
 * quedan como fracción (0.25); los campos que terminan en «_pct» quedan en puntos porcentuales (16.5), igual que en el
 * archivo.
 *
 * PHP puro: sin funciones de WordPress.
 *
 * @package Consolida
 */

declare(strict_types=1);

namespace Consolida\Engine;

use Consolida\Engine\Xlsx\Reader;

final class Corporate
{
    /** Hojas que se leen del archivo corporativo. */
    public const SHEETS = [
        'parametros_paises', 'tasas_oficiales', 'catalogo_cuentas', 'maestro_productos', 'canales',
        'propuestas_inversion', 'historial_ciclos', 'historial_indicadores',
    ];

    /** Columnas de errores de historial_ciclos → categoría de Issue (§7). */
    public const HISTORY_ERROR_COLUMNS = [
        'errores_formato_y_escala'     => 'formato_escala',
        'errores_intercompania'        => 'intercompania',
        'errores_tipo_de_cambio'       => 'tipo_cambio',
        'errores_calculos_y_totales'   => 'calculos_totales',
        'errores_indicadores_copiados' => 'indicadores',
        'errores_catalogo_y_periodo'   => 'catalogo_periodo',
    ];

    /**
     * Lee el archivo corporativo.
     *
     * @throws \RuntimeException Si el archivo no se puede leer o le falta la hoja parametros_paises o catalogo_cuentas.
     */
    public static function load(string $path): array
    {
        $book = Reader::read($path);
        $sheets = [];
        foreach ($book as $name => $rows) {
            $sheets[self::key((string) $name)] = $rows;
        }
        foreach (['parametros_paises', 'catalogo_cuentas'] as $required) {
            if (!isset($sheets[$required])) {
                throw new \RuntimeException('El archivo corporativo no tiene la hoja «' . $required . '».');
            }
        }

        return [
            'companies'      => self::companies(self::table($sheets['parametros_paises'] ?? [])),
            'rates'          => self::rates(self::table($sheets['tasas_oficiales'] ?? [])),
            'catalog'        => self::catalog(self::table($sheets['catalogo_cuentas'] ?? [])),
            'products'       => self::products(self::table($sheets['maestro_productos'] ?? [])),
            'channels'       => self::channels(self::table($sheets['canales'] ?? [])),
            'proposals'      => self::proposals(self::table($sheets['propuestas_inversion'] ?? [])),
            'history_cycles' => self::historyCycles(self::table($sheets['historial_ciclos'] ?? [])),
            'history_kpis'   => self::historyKpis(self::table($sheets['historial_indicadores'] ?? [])),
        ];
    }

    /**
     * Tasa del saldo inicial de una versión (§4): rates[version][cur][0].close; si no existe, el cierre de diciembre
     * del año anterior en real_<año-1>; si no, el cierre del mes 1. USD (o la moneda del grupo) → 1.
     */
    public static function openingRate(array $corporate, string $version, string $currency): ?float
    {
        $currency = strtoupper($currency);
        if ($currency === 'USD') {
            return 1.0;
        }
        $rates = $corporate['rates'] ?? [];
        if (isset($rates[$version][$currency][0]['close'])) {
            return (float) $rates[$version][$currency][0]['close'];
        }
        if (preg_match('/(\d{4})/', $version, $m)) {
            $prev = 'real_' . ((int) $m[1] - 1);
            if (isset($rates[$prev][$currency][12]['close'])) {
                return (float) $rates[$prev][$currency][12]['close'];
            }
        }
        if (isset($rates[$version][$currency][1]['close'])) {
            return (float) $rates[$version][$currency][1]['close'];
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Hojas
    // ------------------------------------------------------------------------------------------------------------

    private static function companies(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $code = strtoupper(self::str($r['codigo'] ?? null) ?? '');
            if ($code === '') {
                continue;
            }
            $number = self::str($r['pais_numero'] ?? null);
            $out[$code] = [
                'code'             => $code,
                'number'           => $number !== null && preg_match('/(\d+)/', $number, $m) ? (int) $m[1] : null,
                'name'             => self::str($r['empresa'] ?? null),
                'country'          => self::str($r['pais'] ?? null),
                'currency'         => strtoupper(self::str($r['moneda'] ?? null) ?? ''),
                'tax'              => self::fraction($r['tasa_impuesto'] ?? null),
                'wacc'             => self::fraction($r['wacc'] ?? null),
                'ownership'        => self::fraction($r['participacion_grupo'] ?? null) ?? 1.0,
                'minority'         => self::fraction($r['participacion_minoritarios'] ?? null) ?? 0.0,
                'minority_partner' => self::str($r['socio_minoritario'] ?? null),
                'scale'            => self::scaleKey(self::str($r['escala_archivo'] ?? null)),
                'data_owner'       => self::str($r['responsable_de_datos'] ?? null),
                'approver'         => self::str($r['aprueba_en_el_pais'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * tasas_oficiales → [version][moneda][mes] = [budget, avg, close, status, approved_by, approved_at].
     * La fila de diciembre del año anterior al de la versión (p. ej. real_2026 con anio 2025, mes 12) es el saldo
     * inicial y se guarda como mes 0.
     */
    private static function rates(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $version = self::str($r['version'] ?? null);
            $cur     = strtoupper(self::str($r['moneda'] ?? null) ?? '');
            $year    = self::num($r['anio'] ?? null);
            $month   = self::num($r['mes'] ?? null);
            if ($version === null || $cur === '' || $month === null) {
                continue;
            }
            $month = (int) $month;
            $vYear = preg_match('/(\d{4})/', $version, $m) ? (int) $m[1] : null;
            if ($vYear !== null && $year !== null && (int) $year === $vYear - 1 && $month === 12) {
                $month = 0;
            }
            $entry = [
                'budget'      => self::num($r['tasa_presupuesto'] ?? null),
                'avg'         => self::num($r['tasa_promedio'] ?? null),
                'close'       => self::num($r['tasa_cierre'] ?? null),
                'status'      => self::str($r['estado'] ?? null),
                'approved_by' => self::str($r['aprobado_por'] ?? null),
                'approved_at' => self::isoDate($r['fecha_aprobacion'] ?? null),
                'against'     => strtoupper(self::str($r['contra'] ?? null) ?? 'USD'),
                'row'         => $r['_row'],
            ];
            if ($month === 0) {
                // El saldo inicial solo trae el cierre; no se inventan promedio ni presupuesto.
                $entry = array_filter($entry, static fn($v, $k) => $v !== null || $k === 'close', ARRAY_FILTER_USE_BOTH);
            }
            $out[$version][$cur][$month] = $entry;
        }
        foreach ($out as &$byCur) {
            foreach ($byCur as &$byMonth) {
                ksort($byMonth);
            }
        }
        unset($byCur, $byMonth);
        return $out;
    }

    private static function catalog(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $code = self::code($r['codigo'] ?? null);
            if ($code === null) {
                continue;
            }
            $sign = self::str($r['signo_esperado'] ?? null) ?? '+';
            $out[$code] = [
                'code'         => $code,
                'name'         => self::str($r['nombre'] ?? null),
                'type'         => self::str($r['tipo'] ?? null),
                'subtype'      => self::str($r['subtipo'] ?? null),
                'operating'    => self::yes($r['operativa'] ?? null) ?? false,
                'current'      => self::yes($r['corriente'] ?? null),
                'intercompany' => self::yes($r['intercompania'] ?? null) ?? false,
                'sign'         => (str_contains($sign, '-') || str_contains($sign, '−')) ? -1 : 1,
                'consolidated_only' => str_contains(mb_strtolower((string) self::str($r['nombre'] ?? null)), 'solo en el consolidado'),
            ];
        }
        return $out;
    }

    private static function products(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $code = strtoupper(self::str($r['codigo'] ?? null) ?? '');
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'name'    => self::str($r['producto'] ?? null),
                'name_en' => self::str($r['product_en'] ?? null),
                'line'    => self::str($r['linea'] ?? null),
            ];
        }
        return $out;
    }

    private static function channels(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $code = strtoupper(self::str($r['codigo'] ?? null) ?? '');
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'name'    => self::str($r['canal'] ?? null),
                'name_en' => self::str($r['channel_en'] ?? null),
            ];
        }
        return $out;
    }

    private static function proposals(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $id = self::str($r['proyecto'] ?? null);
            if ($id === null) {
                continue;
            }
            $out[] = [
                'id'                     => $id,
                'site'                   => strtoupper(self::str($r['sede'] ?? null) ?? ''),
                'description'            => self::str($r['descripcion'] ?? null),
                'investment_usd'         => self::num($r['inversion_usd'] ?? null),
                'life_years'             => self::num($r['vida_util_anios'] ?? null),
                'pct_third_party_sales'  => self::fraction($r['pct_ventas_terceros_sede'] ?? null),
                'incremental_revenue_usd' => self::num($r['ingreso_incremental_usd'] ?? null),
                'incremental_nopat_usd'  => self::num($r['nopat_incremental_esperado_usd'] ?? null),
                'capital_usd'            => self::num($r['capital_proyecto_usd'] ?? null),
                'roic_pct'               => self::num($r['roic_proyecto_pct'] ?? null),
                'wacc_pct'               => self::num($r['wacc_sede_pct'] ?? null),
                'eva_usd'                => self::num($r['eva_usd'] ?? null),
                'decision'               => mb_strtolower((string) self::str($r['decision_propuesta'] ?? null)),
                'row'                    => $r['_row'],
            ];
        }
        return $out;
    }

    private static function historyCycles(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $cycle = self::str($r['ciclo'] ?? null);
            if ($cycle === null) {
                continue;
            }
            $errors = [];
            foreach (self::HISTORY_ERROR_COLUMNS as $col => $category) {
                $errors[$category] = (int) (self::num($r[$col] ?? null) ?? 0);
            }
            $year = preg_match('/(\d{4})/', $cycle, $m) ? (int) $m[1] : null;
            $out[] = [
                'cycle'                  => $cycle,
                'year'                   => $year,
                'version'                => $year !== null ? (stripos($cycle, 'real') !== false ? 'real_' : 'ppto_') . $year : null,
                'company'                => strtoupper(self::str($r['pais_codigo'] ?? null) ?? ''),
                'country'                => self::str($r['pais'] ?? null),
                'sent_at'                => self::isoDate($r['fecha_envio'] ?? null),
                'delay_days'             => self::num($r['dias_de_retraso'] ?? null),
                'resubmissions'          => self::num($r['reenvios'] ?? null),
                'errors_format_scale'    => $errors['formato_escala'],
                'errors_intercompany'    => $errors['intercompania'],
                'errors_fx'              => $errors['tipo_cambio'],
                'errors_calc_totals'     => $errors['calculos_totales'],
                'errors_kpi_copied'      => $errors['indicadores'],
                'errors_catalog_period'  => $errors['catalogo_periodo'],
                'errors'                 => $errors,
                'total_errors'           => self::num($r['total_errores'] ?? null),
                'days_to_consolidate'    => self::num($r['dias_para_consolidar_grupo'] ?? null),
                'analyst_hours'          => self::num($r['horas_analista_grupo'] ?? null),
                'row'                    => $r['_row'],
            ];
        }
        return $out;
    }

    private static function historyKpis(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $year = self::num($r['anio'] ?? null);
            if ($year === null) {
                continue;
            }
            $out[] = [
                'year'                 => (int) $year,
                'kind'                 => self::str($r['tipo'] ?? null),
                'company'              => strtoupper(self::str($r['pais_codigo'] ?? null) ?? ''),
                'country'              => self::str($r['pais'] ?? null),
                'revenue_usd'          => self::num($r['ingresos_usd'] ?? null),
                'ebit_usd'             => self::num($r['ebit_usd'] ?? null),
                'nopat_usd'            => self::num($r['nopat_usd'] ?? null),
                'invested_capital_usd' => self::num($r['capital_invertido_usd'] ?? null),
                'roic_pct'             => self::num($r['roic_pct'] ?? null),
                'wacc_pct'             => self::num($r['wacc_pct'] ?? null),
                'eva_usd'              => self::num($r['eva_usd'] ?? null),
                'row'                  => $r['_row'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Convierte una hoja con encabezado (la primera fila con texto) en filas asociativas por nombre de columna
     * normalizado. Cada fila trae '_row' con la fila real de Excel.
     */
    private static function table(array $rows): array
    {
        $header = null;
        $out = [];
        foreach ($rows as $rowNum => $cells) {
            if ($header === null) {
                $header = [];
                foreach ($cells as $col => $v) {
                    $k = self::key((string) $v);
                    if ($k !== '') {
                        $header[$col] = $k;
                    }
                }
                continue;
            }
            $assoc = ['_row' => (int) $rowNum];
            $any = false;
            foreach ($header as $col => $k) {
                if (array_key_exists($col, $cells)) {
                    $assoc[$k] = $cells[$col];
                    $any = true;
                }
            }
            if ($any) {
                $out[] = $assoc;
            }
        }
        return $out;
    }

    /** Nombre de columna u hoja normalizado: minúsculas, sin acentos, espacios → «_». */
    private static function key(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null || is_bool($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function code(mixed $v): ?string
    {
        if (is_float($v) && floor($v) === $v) {
            $v = (int) $v;
        }
        return self::str($v);
    }

    private static function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = self::str($v);
        if ($s === null) {
            return null;
        }
        $s = str_replace([',', ' ', '%'], '', $s);
        return is_numeric($s) ? (float) $s : null;
    }

    /** Porcentaje como fracción: 0.25 se queda; 25 (o «25 %») → 0.25. */
    private static function fraction(mixed $v): ?float
    {
        $n = self::num($v);
        if ($n === null) {
            return null;
        }
        return abs($n) > 1.0 ? $n / 100 : $n;
    }

    /** si/no → bool; vacío → null. */
    private static function yes(mixed $v): ?bool
    {
        $s = self::str($v);
        if ($s === null) {
            return null;
        }
        $s = self::key($s);
        if (in_array($s, ['si', 's', 'yes', 'y', 'true', '1', 'x'], true)) {
            return true;
        }
        if (in_array($s, ['no', 'n', 'false', '0'], true)) {
            return false;
        }
        return null;
    }

    /** Escala declarada en texto → 'unidades' | 'miles' | 'millones'. */
    public static function scaleKey(?string $s): string
    {
        $k = self::key((string) $s);
        if ($k === '') {
            return 'unidades';
        }
        if (preg_match('/millon|million|mm_?usd/', $k)) {
            return 'millones';
        }
        if (preg_match('/miles|mil\b|thousand|000|k_?usd/', $k)) {
            return 'miles';
        }
        return 'unidades';
    }

    /** dd/mm/aaaa (o serial de Excel, o aaaa-mm-dd) → aaaa-mm-dd; null si no se reconoce. */
    public static function isoDate(mixed $v, string $order = 'dmy'): ?string
    {
        if (is_int($v) || is_float($v)) {
            $n = (int) $v;
            if ($n > 20000 && $n < 80000) {
                return gmdate('Y-m-d', ($n - 25569) * 86400);
            }
            return null;
        }
        $s = self::str($v);
        if ($s === null) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $s, $m)) {
            [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            [$d, $mo] = $order === 'mdy' ? [$b, $a] : [$a, $b];
            if (!checkdate($mo, $d, $y)) {
                [$d, $mo] = [$mo, $d]; // el otro orden, por si el archivo no respeta el formato
            }
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        }
        return null;
    }
}
