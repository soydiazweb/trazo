<?php
/**
 * Inspección de archivos del Grupo Meridia desde la línea de comandos (sin WordPress).
 *
 *   php tools/inspect.php <archivo.xlsx>              Resumen del Dataset de un archivo de país y sus issues.
 *   php tools/inspect.php <archivo.xlsx> --json       El Dataset completo en JSON.
 *   php tools/inspect.php --all <carpeta datos-meridia>  Recorre los 21 archivos: tabla de lectura e issues.
 *   php tools/inspect.php <Grupo_Corporativo.xlsx>    Resumen del archivo corporativo.
 *
 * Opciones: --corp=<ruta Grupo_Corporativo.xlsx> (si no, se busca 2_Grupo/Grupo_Corporativo.xlsx hacia arriba o en
 * la variable de entorno CSL_DATA).
 *
 * @package Consolida
 */

declare(strict_types=1);

use Consolida\Engine\CountryReader;
use Consolida\Engine\Corporate;
use Consolida\Engine\Profiles;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$engine = dirname(__DIR__) . '/includes/Engine';
require_once $engine . '/Xlsx/Reader.php';
require_once $engine . '/Corporate.php';
require_once $engine . '/Profiles.php';
require_once $engine . '/CountryReader.php';

$args = array_slice($argv, 1);
$opts = ['all' => false, 'json' => false, 'corp' => null];
$paths = [];
foreach ($args as $a) {
    if ($a === '--all') {
        $opts['all'] = true;
    } elseif ($a === '--json') {
        $opts['json'] = true;
    } elseif (str_starts_with($a, '--corp=')) {
        $opts['corp'] = substr($a, 7);
    } else {
        $paths[] = $a;
    }
}
if (!$paths) {
    fwrite(STDERR, "Uso: php tools/inspect.php <archivo.xlsx> [--json] | --all <carpeta datos-meridia> [--corp=ruta]\n");
    exit(2);
}

/** Busca Grupo_Corporativo.xlsx: --corp, CSL_DATA o subiendo desde la ruta dada. */
function find_corporate(string $start, ?string $explicit): ?string
{
    if ($explicit !== null) {
        return is_file($explicit) ? $explicit : null;
    }
    $env = getenv('CSL_DATA');
    if ($env && is_file($env . '/2_Grupo/Grupo_Corporativo.xlsx')) {
        return $env . '/2_Grupo/Grupo_Corporativo.xlsx';
    }
    $dir = is_dir($start) ? $start : dirname($start);
    for ($i = 0; $i < 6 && $dir !== '/' && $dir !== '.'; $i++) {
        foreach (['/2_Grupo/Grupo_Corporativo.xlsx', '/datos-meridia/2_Grupo/Grupo_Corporativo.xlsx', '/Grupo_Corporativo.xlsx'] as $rel) {
            if (is_file($dir . $rel)) {
                return $dir . $rel;
            }
        }
        $dir = dirname($dir);
    }
    return null;
}

function n(?float $v, int $d = 2): string
{
    return CountryReader::fmt($v, $d);
}

function pad(string $s, int $w, bool $right = false): string
{
    $len = mb_strlen($s);
    if ($len >= $w) {
        return $s;
    }
    return $right ? str_repeat(' ', $w - $len) . $s : $s . str_repeat(' ', $w - $len);
}

function issue_line(array $i): string
{
    $where = ($i['sheet'] ?? '-') . '!' . ($i['col'] ?? '') . ($i['row'] ?? '');
    return sprintf('  %-15s %-7s %-22s %s', $i['code'], $i['severity'], $where, $i['message']);
}

/** Valores leídos de un Dataset (celdas de resultados, balance, ventas, intercompañía, inventario, capex, indicadores). */
function value_count(array $ds): int
{
    $n = count($ds['pl']) + count($ds['bs']) + count($ds['ic']) + count($ds['ic_inventory']) + count($ds['capex']);
    $n += count($ds['sales']) * 2;
    if (is_array($ds['reported'])) {
        $n += count($ds['reported']['rows']);
    }
    return $n;
}

function summarize_corporate(array $c): void
{
    echo "Corporativo\n";
    foreach ($c['companies'] as $code => $co) {
        printf("  %s %-18s %s  impuesto %.2f  WACC %.2f  participación %.2f  escala %s\n", $code, $co['name'], $co['currency'],
            $co['tax'], $co['wacc'], $co['ownership'], $co['scale']);
    }
    foreach ($c['rates'] as $v => $byCur) {
        foreach ($byCur as $cur => $months) {
            printf("  tasas %-13s %s: meses %s  saldo inicial %s\n", $v, $cur, implode(',', array_keys($months)),
                Corporate::openingRate($c, $v, $cur));
        }
    }
    printf("  catálogo %d cuentas · %d productos · %d canales · %d propuestas · historial %d ciclos, %d indicadores\n",
        count($c['catalog']), count($c['products']), count($c['channels']), count($c['proposals']),
        count($c['history_cycles']), count($c['history_kpis']));
}

function summarize_dataset(array $ds, array $corp): void
{
    $m = $ds['meta'];
    printf("%s · %s · versión %s (año %s) · moneda %s · escala declarada %s (%s), aplicada ×%s\n", $m['file'], $m['company'],
        $m['version'] ?? '?', $m['year'] ?? '?', $m['currency'] ?? '?', $m['scale_declared'], $m['scale_source'], $m['scale_applied']);
    printf("  preparado por %s el %s · aprobado por %s el %s\n", $m['prepared_by'] ?? '-', $m['loaded_at'] ?? '-',
        $m['approved_by'] ?? '-', $m['approved_at'] ?? '-');
    printf("  valores leídos: %s  (pl %d · bs %d · ventas %d filas · ic %d · inventario %d · capex %d · indicadores %s · sin mapear %d)\n",
        n(value_count($ds), 0), count($ds['pl']), count($ds['bs']), count($ds['sales']), count($ds['ic']),
        count($ds['ic_inventory']), count($ds['capex']), $ds['reported'] ? count($ds['reported']['rows']) : 0, count($ds['unmapped']));

    foreach (['pl' => 'resultados', 'bs' => 'balance'] as $key => $title) {
        $by = [];
        foreach ($ds[$key] as $r) {
            $by[$r['concept']]['months'][$r['month']] = $r['value'];
            $by[$r['concept']]['label'] ??= $r['label'];
        }
        echo "\n  {$title}: " . count($by) . " conceptos\n";
        foreach ($by as $concept => $info) {
            $months = $info['months'];
            ksort($months);
            $nulls = count(array_filter($months, static fn($v) => $v === null));
            $cover = $key === 'pl' ? range(1, 12) : range(0, 12);
            $missing = array_diff($cover, array_keys($months));
            $flag = ($missing ? ' faltan ' . implode(',', $missing) : '') . ($nulls ? " vacíos {$nulls}" : '');
            if ($key === 'pl') {
                $total = 0.0;
                foreach ($months as $mo => $v) {
                    if ($mo >= 1) {
                        $total += (float) $v;
                    }
                }
                $isPrice = str_starts_with((string) $concept, 'price:');
                printf("    %s %s %2d meses  %s %s%s\n", pad((string) $concept, 22), pad(mb_substr($info['label'], 0, 46), 46), count($months),
                    $isPrice ? 'promedio' : 'año', pad(n($isPrice ? $total / max(1, count($months)) : $total), 20, true), $flag);
            } else {
                printf("    %-6s %s %2d saldos  inicial %s  dic %s%s\n", $concept, pad(mb_substr($info['label'], 0, 40), 40), count($months),
                    pad(n($months[0] ?? null), 18, true), pad(n($months[12] ?? null), 18, true), $flag);
            }
        }
    }

    if ($ds['sales']) {
        $by = [];
        $months = [];
        foreach ($ds['sales'] as $s) {
            $k = ($s['line'] ?? '?') . ' / ' . ($s['channel'] ?? '?');
            $by[$k]['volume'] = ($by[$k]['volume'] ?? 0) + (float) $s['volume'];
            $by[$k]['amount'] = ($by[$k]['amount'] ?? 0) + (float) $s['amount'];
            $months[$s['month']] = ($months[$s['month']] ?? 0) + 1;
        }
        ksort($by);
        ksort($months);
        echo "\n  ventas_detalle: " . count($ds['sales']) . ' filas; filas por mes ' . implode(' ', array_map(static fn($k, $v) => "{$k}:{$v}", array_keys($months), $months)) . "\n";
        foreach ($by as $k => $v) {
            printf("    %s volumen %14s  ventas %20s\n", pad($k, 26), n($v['volume'], 0), n($v['amount']));
        }
    }
    if ($ds['ic']) {
        $by = [];
        foreach ($ds['ic'] as $r) {
            $k = sprintf('%s %s %s %s', $r['counterparty'] ?? '?', $r['type'] ?? '?', $r['direction'] ?? '?', $r['currency']);
            $by[$k]['n'] = ($by[$k]['n'] ?? 0) + 1;
            $by[$k]['amount'] = ($by[$k]['amount'] ?? 0) + (float) $r['amount'];
        }
        ksort($by);
        echo "\n  intercompania: " . count($ds['ic']) . " filas (monto en moneda pactada, sin escala)\n";
        foreach ($by as $k => $v) {
            printf("    %-44s %3d filas  %20s\n", $k, $v['n'], n($v['amount']));
        }
    }
    if ($ds['ic_inventory']) {
        $by = [];
        foreach ($ds['ic_inventory'] as $r) {
            $by[($r['supplier'] ?? '?') . ' ' . ($r['line'] ?? '?')][$r['month']] = $r['amount'];
        }
        echo "\n  inventario_grupo:\n";
        foreach ($by as $k => $months) {
            printf("    %s %2d saldos  inicial %16s  dic %16s\n", pad($k, 22), count($months), n($months[0] ?? null), n($months[12] ?? null));
        }
    }
    if ($ds['capex']) {
        $tot = [];
        foreach ($ds['capex'] as $r) {
            $tot[$r['project'] ?? '?'] = ($tot[$r['project'] ?? '?'] ?? 0) + (float) $r['amount'];
        }
        echo "\n  capex: " . count($ds['capex']) . ' filas · ' . implode(' · ', array_map(static fn($k, $v) => $k . ' ' . n($v), array_keys($tot), $tot)) . "\n";
    }
    if ($ds['reported']) {
        echo "\n  indicadores_reportados (montos en unidades locales):\n";
        foreach ($ds['reported'] as $k => $v) {
            if ($k !== 'rows') {
                printf("    %-14s %20s\n", $k, $v === null ? '-' : n($v, 4));
            }
        }
    }
    if ($ds['unmapped']) {
        echo "\n  sin mapear (no entran a pl/bs): " . count($ds['unmapped']) . " valores\n";
    }
    echo "\n  issues de lectura: " . count($ds['issues']) . "\n";
    foreach ($ds['issues'] as $i) {
        echo issue_line($i) . "\n";
    }
}

// ------------------------------------------------------------------------------------------------------------------

try {
    if ($opts['all']) {
        $root = rtrim($paths[0], '/');
        $corpPath = find_corporate($root, $opts['corp']);
        if ($corpPath === null) {
            throw new RuntimeException('No se encontró Grupo_Corporativo.xlsx (use --corp=ruta).');
        }
        $corp = Corporate::load($corpPath);
        summarize_corporate($corp);
        $files = glob($root . '/1_Paises/*/*.xlsx') ?: glob($root . '/*/*.xlsx') ?: [];
        $profiles = Profiles::defaults();
        $order = array_flip(array_keys($profiles));
        $vorder = ['ppto_2026' => 0, 'real_2026' => 1, 'ppto_2027_v1' => 2, 'ppto_2027_v2' => 3];
        usort($files, static function ($a, $b) use ($profiles, $order, $corp, $vorder) {
            $ka = [$order[Profiles::detect($a, $profiles)] ?? 9, $vorder[CountryReader::detectVersion(basename($a), $corp)] ?? 9];
            $kb = [$order[Profiles::detect($b, $profiles)] ?? 9, $vorder[CountryReader::detectVersion(basename($b), $corp)] ?? 9];
            return $ka <=> $kb;
        });
        echo "\n" . pad('archivo', 40) . pad('país', 5) . pad('versión', 14) . pad('valores', 9, true) . pad('pl', 6, true)
            . pad('bs', 6, true) . pad('ventas', 7, true) . pad('ic', 5, true) . pad('inv', 5, true) . pad('capex', 6, true)
            . pad('ind', 5, true) . '  issues de lectura' . "\n";
        $all = [];
        foreach ($files as $f) {
            $code = Profiles::detect($f, $profiles);
            if ($code === null) {
                printf("%s  (no se reconoce el país)\n", basename($f));
                continue;
            }
            try {
                $ds = CountryReader::read($f, $profiles[$code], $corp);
            } catch (Throwable $e) {
                printf("%s  ERROR: %s\n", basename($f), $e->getMessage());
                continue;
            }
            $codes = array_count_values(array_column($ds['issues'], 'code'));
            ksort($codes);
            echo pad(basename($f), 40) . pad($code, 5) . pad((string) $ds['meta']['version'], 14)
                . pad(n(value_count($ds), 0), 9, true) . pad((string) count($ds['pl']), 6, true) . pad((string) count($ds['bs']), 6, true)
                . pad((string) count($ds['sales']), 7, true) . pad((string) count($ds['ic']), 5, true)
                . pad((string) count($ds['ic_inventory']), 5, true) . pad((string) count($ds['capex']), 6, true)
                . pad($ds['reported'] ? (string) count($ds['reported']['rows']) : '-', 5, true) . '  '
                . ($codes ? implode(' ', array_map(static fn($k, $v) => "{$k}×{$v}", array_keys($codes), $codes)) : 'ninguno') . "\n";
            $all[basename($f)] = $ds['issues'];
        }
        echo "\nDetalle de issues de lectura\n";
        foreach ($all as $file => $issues) {
            if (!$issues) {
                continue;
            }
            echo $file . "\n";
            foreach ($issues as $i) {
                echo issue_line($i) . "\n";
            }
        }
        exit(0);
    }

    $path = $paths[0];
    if (stripos(basename($path), 'corporativo') !== false) {
        summarize_corporate(Corporate::load($path));
        exit(0);
    }
    $corpPath = find_corporate($path, $opts['corp']);
    if ($corpPath === null) {
        throw new RuntimeException('No se encontró Grupo_Corporativo.xlsx (use --corp=ruta).');
    }
    $corp = Corporate::load($corpPath);
    $profiles = Profiles::defaults();
    $code = Profiles::detect($path, $profiles);
    if ($code === null) {
        throw new RuntimeException('No se reconoce el país por el nombre del archivo: ' . basename($path));
    }
    $ds = CountryReader::read($path, $profiles[$code], $corp);
    if ($opts['json']) {
        echo json_encode($ds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    summarize_dataset($ds, $corp);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
