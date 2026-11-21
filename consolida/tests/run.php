<?php
/**
 * Pruebas del motor financiero v2 (sin framework): `php tests/run.php`. Código de salida ≠ 0 si alguna falla.
 *
 * (a) Unitarias con un Corporate y Datasets §6 hechos a mano (dos empresas: AA en «LCL» y BB en USD con 20 % de
 *     minoritarios), con resultados calculados a mano.
 * (b) Integración con los archivos reales de `datos-meridia/` (ruta relativa al plugin o variable CSL_DATA) si existen
 *     Corporate.php, Profiles.php y CountryReader.php: lee los 21 archivos, valida, concilia, consolida las 4 versiones,
 *     KPIs, propuestas y desviaciones, y escribe `tests/hallazgos.md`.
 */

declare( strict_types=1 );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Consolida\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
ini_set( 'memory_limit', '2G' );

use Consolida\Engine\Consolidator;
use Consolida\Engine\Fx;
use Consolida\Engine\History;
use Consolida\Engine\Intercompany;
use Consolida\Engine\Kpi;
use Consolida\Engine\Support;
use Consolida\Engine\Validator;
use Consolida\Engine\Variance;

/* ====================================================================== mini framework */

$GLOBALS['t_pass']    = 0;
$GLOBALS['t_fail']    = [];
$GLOBALS['t_section'] = '';
$GLOBALS['t_quiet']   = in_array( '-q', $argv ?? [], true );

function section( string $name ): void {
	$GLOBALS['t_section'] = $name;
	echo "\n== {$name}\n";
}

function check( string $name, bool $ok, string $detail = '' ): void {
	if ( $ok ) {
		$GLOBALS['t_pass']++;
		if ( ! $GLOBALS['t_quiet'] ) {
			echo "  ok    {$name}\n";
		}
		return;
	}
	$GLOBALS['t_fail'][] = $GLOBALS['t_section'] . ' › ' . $name . ( $detail ? " ({$detail})" : '' );
	echo "  FALLA {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}

function near( string $name, ?float $actual, float $expected, float $tol = 0.01 ): void {
	check( $name, null !== $actual && abs( $actual - $expected ) <= $tol, sprintf( 'obtenido %s, esperado %s', var_export( $actual, true ), var_export( $expected, true ) ) );
}

function find( array $issues, string $code, ?string $company = null, ?int $month = null ): array {
	return array_values( array_filter( $issues, fn( $i ) => $i['code'] === $code && ( null === $company || $i['company'] === $company ) && ( null === $month || $i['month'] === $month ) ) );
}

function codes( array $issues ): string {
	return implode( ', ', array_map( fn( $i ) => $i['code'] . '@' . $i['company'] . ':' . ( $i['month'] ?? '-' ), $issues ) ) ?: '(ninguno)';
}

/* ====================================================================== datos hechos a mano */

const V  = 'ppto_2027_v1';
const VB = 'real_2026';

/** Corporate §4 mínimo (catálogo con los atributos reales de las cuentas que se usan). */
function corp(): array {
	$acc = fn( $code, $name, $type, $sub, $op, $cur, $ic, $sign = 1, $only = false ) => [ 'code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $sub, 'operating' => $op, 'current' => $cur, 'intercompany' => $ic, 'sign' => $sign, 'consolidated_only' => $only ];
	$cat = [];
	foreach ( [
		[ '4101', 'Ventas a terceros - Snacks', 'ingreso', 'ventas', true, null, false ],
		[ '4111', 'Ventas intercompañía - Snacks', 'ingreso', 'ventas', true, null, true ],
		[ '4901', 'Ingreso por cuota de administración', 'ingreso', 'otros_ingresos_operativos', true, null, true ],
		[ '5101', 'Costo de ventas - Snacks', 'costo', 'costo_ventas', true, null, false ],
		[ '6101', 'Gastos de personal', 'gasto', 'gasto_operacion', true, null, false ],
		[ '6103', 'Mercadeo', 'gasto', 'gasto_operacion', true, null, false ],
		[ '6105', 'Cuota de administración pagada a GT', 'gasto', 'gasto_operacion', true, null, true ],
		[ '6201', 'Depreciación', 'gasto', 'depreciacion', true, null, false ],
		[ '7101', 'Ingresos financieros', 'ingreso', 'financiero', false, null, false ],
		[ '7111', 'Intereses intercompañía ganados', 'ingreso', 'financiero', false, null, true ],
		[ '7112', 'Intereses intercompañía pagados', 'gasto', 'financiero', false, null, true ],
		[ '8101', 'Impuesto sobre la renta', 'gasto', 'impuesto', false, null, false ],
		[ '1101', 'Efectivo', 'activo', 'efectivo', false, true, false ],
		[ '1201', 'Cuentas por cobrar a terceros', 'activo', 'capital_trabajo', true, true, false ],
		[ '1202', 'Cuentas por cobrar intercompañía', 'activo', 'capital_trabajo', true, true, true ],
		[ '1301', 'Inventario', 'activo', 'capital_trabajo', true, true, false ],
		[ '1302', 'Inventario comprado a empresas del grupo', 'activo', 'capital_trabajo', true, true, true ],
		[ '1401', 'Préstamo intercompañía por cobrar', 'activo', 'financiero', false, false, true ],
		[ '1501', 'Activo fijo bruto', 'activo', 'activo_fijo', true, false, false ],
		[ '1502', 'Depreciación acumulada', 'activo', 'activo_fijo', true, false, false, -1 ],
		[ '2101', 'Cuentas por pagar a terceros', 'pasivo', 'capital_trabajo', true, true, false ],
		[ '2102', 'Cuentas por pagar intercompañía', 'pasivo', 'capital_trabajo', true, true, true ],
		[ '2301', 'Préstamo intercompañía por pagar', 'pasivo', 'deuda', false, false, true ],
		[ '3101', 'Patrimonio', 'patrimonio', 'patrimonio', false, false, false ],
		[ '3102', 'Interés minoritario (solo en el consolidado)', 'patrimonio', 'patrimonio', false, false, false, 1, true ],
		[ '5901', 'Eliminación de margen no realizado en inventario (solo en el consolidado)', 'costo', 'costo_ventas', true, null, true, 1, true ],
	] as $a ) {
		$cat[ $a[0] ] = $acc( ...$a );
	}
	$rates = [];
	foreach ( [ V => [ 10.0, 10.0 ], VB => [ 8.0, 8.0 ] ] as $ver => [ $avg, $close ] ) {
		for ( $m = 1; $m <= 12; $m++ ) {
			$rates[ $ver ]['LCL'][ $m ] = [ 'budget' => $avg, 'avg' => $avg, 'close' => $close ];
		}
	}
	$rates[ V ]['LCL'][0] = [ 'close' => 10.0 ];
	return [
		'companies' => [
			'AA' => [ 'code' => 'AA', 'name' => 'Meridia Alfa', 'country' => 'Alfa', 'currency' => 'LCL', 'tax' => 0.25, 'wacc' => 0.11, 'ownership' => 1.0, 'minority' => 0.0, 'scale' => 'unidades' ],
			'BB' => [ 'code' => 'BB', 'name' => 'Meridia Beta', 'country' => 'Beta', 'currency' => 'USD', 'tax' => 0.21, 'wacc' => 0.09, 'ownership' => 0.8, 'minority' => 0.2, 'scale' => 'unidades' ],
		],
		'rates'     => $rates,
		'catalog'   => $cat,
		'products'  => [ 'SNK-01' => [ 'name' => 'Papas', 'name_en' => 'Chips', 'line' => 'Snacks' ] ],
		'channels'  => [ 'C1' => [ 'name' => 'Supermercados', 'name_en' => 'Supermarkets' ], 'C4' => [ 'name' => 'Empresas del grupo', 'name_en' => 'Group companies' ] ],
		'proposals' => [
			[ 'id' => 'P1', 'site' => 'AA', 'investment_usd' => 1000, 'capital_usd' => 1000, 'incremental_nopat_usd' => 140, 'roic_pct' => 14, 'wacc_pct' => 11, 'eva_usd' => 30, 'decision' => 'aprobar' ],
			[ 'id' => 'P2', 'site' => 'BB', 'investment_usd' => 1000, 'capital_usd' => 950, 'incremental_nopat_usd' => 100, 'roic_pct' => 10.53, 'wacc_pct' => 8, 'eva_usd' => 28, 'decision' => 'aprobar' ],
		],
		'history_cycles' => [
			[ 'cycle' => 'Presupuesto 2026', 'year' => 2026, 'company' => 'AA', 'errors' => [ 'formato_escala' => 2, 'intercompania' => 1, 'tipo_cambio' => 0, 'calculos_totales' => 3, 'indicadores' => 0, 'catalogo_periodo' => 0 ], 'total_errors' => 6 ],
		],
		'history_kpis' => [
			[ 'year' => 2025, 'kind' => 'real', 'company' => 'AA', 'revenue_usd' => 100, 'ebit_usd' => 10, 'nopat_usd' => 7.5, 'invested_capital_usd' => 50, 'roic_pct' => 15, 'wacc_pct' => 11, 'eva_usd' => 2 ],
		],
	];
}

function src( string $sheet, int $row, string $col = 'B' ): array {
	return [ 'sheet' => $sheet, 'row' => $row, 'col' => $col ];
}

/**
 * AA (moneda LCL, tasa 10): por mes vende 1,000 cajas × 100 = 100,000 (80,000 a terceros y 20,000 = 2,000 USD a BB),
 * cobra cuota de 1,000 USD (10,000) a BB, costo 60,000, personal 10,000, depreciación 5,000, impuesto 5,000.
 */
function ds_aa( string $version = V, float $volume = 1000, float $third = 80000, ?float $rate = 10.0 ): array {
	$rate = $rate ?? 10.0;
	$ds   = [ 'meta' => [ 'company' => 'AA', 'version' => $version, 'year' => Support::version_year( $version ), 'currency' => 'LCL', 'scale_declared' => 'unidades', 'scale_applied' => 1, 'file' => 'Alfa.xlsx' ],
		'pl' => [], 'bs' => [], 'sales' => [], 'ic' => [], 'ic_inventory' => [], 'capex' => [], 'reported' => null, 'issues' => [] ];
	$icl  = 2000 * $rate;
	for ( $m = 1; $m <= 12; $m++ ) {
		$r = 0;
		foreach ( [ 'volume:Snacks' => $volume, 'price:Snacks' => 100.0, 'net_sales:Snacks' => $third + $icl, '4101' => $third, '4111' => $icl, '4901' => 1000 * $rate, '5101' => 60000.0, '6101' => 10000.0, '6103' => 0.0, '6201' => 5000.0, '8101' => 5000.0 ] as $c => $v ) {
			$ds['pl'][] = [ 'concept' => (string) $c, 'label' => (string) $c, 'month' => $m, 'value' => $v, 'src' => src( 'resultados', 3 + ++$r, chr( 65 + $m ) ) ];
		}
		$ds['sales'][] = [ 'month' => $m, 'line' => 'Snacks', 'product' => 'SNK-01', 'channel' => 'C1', 'volume' => $volume * 0.8, 'amount' => $third, 'src' => src( 'ventas_detalle', 2 * $m, 'G' ) ];
		$ds['sales'][] = [ 'month' => $m, 'line' => 'Snacks', 'product' => 'SNK-01', 'channel' => 'C4', 'volume' => $volume * 0.2, 'amount' => $icl, 'src' => src( 'ventas_detalle', 2 * $m + 1, 'G' ) ];
		$ds['ic'][]    = [ 'month' => $m, 'counterparty' => 'BB', 'type' => 'product_sale', 'line' => 'Snacks', 'direction' => 'sale', 'amount' => 2000.0, 'currency' => 'USD', 'note' => null, 'src' => src( 'intercompania', 2 * $m, 'F' ) ];
		$ds['ic'][]    = [ 'month' => $m, 'counterparty' => 'Meridia Beta', 'type' => 'management_fee', 'line' => null, 'direction' => 'charge', 'amount' => 1000.0, 'currency' => 'USD', 'note' => null, 'src' => src( 'intercompania', 2 * $m + 1, 'F' ) ];
	}
	for ( $m = 0; $m <= 12; $m++ ) {
		$r = 0;
		foreach ( [ '1101' => 120000 + 35000 * $m, '1201' => 50000, '1202' => 20000, '1301' => 40000, '1302' => 0, '1501' => 500000, '1502' => 100000 + 5000 * $m, '2101' => 30000, '2102' => 0, '3101' => 600000 + 30000 * $m ] as $c => $v ) {
			$ds['bs'][] = [ 'concept' => (string) $c, 'label' => (string) $c, 'month' => $m, 'value' => (float) $v, 'src' => src( 'balance', 3 + ++$r, chr( 66 + $m ) ) ];
		}
	}
	return $ds;
}

/** BB (USD): vende 500 × 20 a terceros, compra a AA 2,000 y paga cuota 1,000 por mes; inventario de AA 1,000 + 100 × mes. */
function ds_bb( string $version = V, float $volume = 500 ): array {
	$ds = [ 'meta' => [ 'company' => 'BB', 'version' => $version, 'year' => Support::version_year( $version ), 'currency' => 'USD', 'scale_declared' => 'unidades', 'scale_applied' => 1, 'file' => 'Beta.xlsx' ],
		'pl' => [], 'bs' => [], 'sales' => [], 'ic' => [], 'ic_inventory' => [], 'capex' => [], 'reported' => null, 'issues' => [] ];
	$net = $volume * 20;
	for ( $m = 1; $m <= 12; $m++ ) {
		$r = 0;
		foreach ( [ 'volume:Snacks' => $volume, 'price:Snacks' => 20.0, 'net_sales:Snacks' => $net, '4101' => $net, '4111' => 0.0, '5101' => 6000.0, '6101' => 1000.0, '6105' => 1000.0, '6201' => 500.0, '8101' => 500.0 ] as $c => $v ) {
			$ds['pl'][] = [ 'concept' => (string) $c, 'label' => (string) $c, 'month' => $m, 'value' => $v, 'src' => src( 'resultados', 3 + ++$r, chr( 65 + $m ) ) ];
		}
		$ds['sales'][] = [ 'month' => $m, 'line' => 'Snacks', 'product' => 'SNK-01', 'channel' => 'Supermarkets', 'volume' => $volume, 'amount' => $net, 'src' => src( 'ventas_detalle', $m + 1, 'G' ) ];
		$ds['ic'][]    = [ 'month' => $m, 'counterparty' => 'AA', 'type' => 'product_sale', 'line' => 'Snacks', 'direction' => 'purchase', 'amount' => 2000.0, 'currency' => 'USD', 'note' => null, 'src' => src( 'intercompania', 2 * $m, 'F' ) ];
		$ds['ic'][]    = [ 'month' => $m, 'counterparty' => 'AA', 'type' => 'management_fee', 'line' => null, 'direction' => 'payment', 'amount' => 1000.0, 'currency' => 'USD', 'note' => null, 'src' => src( 'intercompania', 2 * $m + 1, 'F' ) ];
	}
	for ( $m = 0; $m <= 12; $m++ ) {
		$ds['ic_inventory'][] = [ 'month' => $m, 'supplier' => 'AA', 'line' => 'Snacks', 'amount' => 1000.0 + 100 * $m, 'src' => src( 'inventario_grupo', $m + 2, 'D' ) ];
		$r = 0;
		foreach ( [ '1101' => 10000 + 1400 * $m, '1201' => 5000, '1202' => 0, '1301' => 3000, '1302' => 1000 + 100 * $m, '1501' => 20000, '1502' => 5000 + 500 * $m, '2101' => 2000, '2102' => 2000, '3101' => 30000 + 1000 * $m ] as $c => $v ) {
			$ds['bs'][] = [ 'concept' => (string) $c, 'label' => (string) $c, 'month' => $m, 'value' => (float) $v, 'src' => src( 'balance', 3 + ++$r, chr( 66 + $m ) ) ];
		}
	}
	return $ds;
}

/** Cambia el valor de un concepto en un mes (pl o bs). */
function set( array $ds, string $part, string $concept, int $month, ?float $value ): array {
	foreach ( $ds[ $part ] as &$r ) {
		if ( $r['concept'] === $concept && $r['month'] === $month ) {
			$r['value'] = $value;
		}
	}
	return $ds;
}

function validate( array $ds, array $others = [] ): array {
	return Validator::validate( $ds, [ 'corporate' => corp(), 'version' => $ds['meta']['version'], 'others' => $others ] );
}

/** Reportados de AA que cuadran (calculados a mano). */
function reported_aa(): array {
	return [ 'ebit' => 420000.0, 'tax_rate' => 25.0, 'nopat' => 315000.0, 'nowc_avg' => 80000.0, 'net_fixed_avg' => 370000.0, 'ic_avg' => 450000.0, 'roic_pct' => 70.0,
		'ce_avg' => 780000.0, 'roce_pct' => 53.8, 'wacc_pct' => 11.0, 'eva_local' => 265500.0, 'fx_used' => 10.0, 'eva_usd' => 26550.0,
		'rows' => [ 'ebit' => src( 'indicadores_reportados', 2 ), 'roic_pct' => src( 'indicadores_reportados', 8 ), 'ic_avg' => src( 'indicadores_reportados', 7 ), 'wacc_pct' => src( 'indicadores_reportados', 11 ), 'fx_used' => src( 'indicadores_reportados', 13 ) ] ];
}

/* ====================================================================== (a) unitarias */

section( 'Support' );
check( 'fmt es-GT', '1,234,567.89' === Support::fmt( 1234567.891 ) && '1,200,000' === Support::fmt( 1200000.0 ) && '0' === Support::fmt( -0.001 ) );
check( 'pct', '11.8 %' === Support::pct( 0.1184 ) );
$roles = Support::roles( corp() );
check( 'roles desde atributos del catálogo', '4111' === $roles['sales_ic']['Snacks'] && '4101' === $roles['sales_third']['Snacks'] && '5101' === $roles['cogs']['Snacks']
	&& '4901' === $roles['mgmt_income'] && '6105' === $roles['mgmt_expense'] && '7111' === $roles['int_income'] && '7112' === $roles['int_expense']
	&& '1202' === $roles['ic_receivable'] && '1302' === $roles['ic_inventory'] && '2102' === $roles['ic_payable'] && '1401' === $roles['loan_receivable']
	&& '2301' === $roles['loan_payable'] && '5901' === $roles['margin_elim'] && '3102' === $roles['minority'] && '3101' === $roles['equity'], json_encode( $roles ) );
check( 'pasivo corriente y activo fijo', [ '2101', '2102' ] === $roles['current_liabilities'] && [ '1501', '1502' ] === $roles['fixed'] );
check( 'yes() acepta si/sí/true', Support::yes( 'Sí' ) && Support::yes( true ) && ! Support::yes( 'no' ) );

section( 'Fx' );
$c = corp();
near( 'avg del mes', Fx::rate( $c, V, 'LCL', 3, 'avg' ), 10 );
near( 'USD = 1', Fx::rate( $c, V, 'USD', 3, 'avg' ), 1 );
near( 'saldo inicial = rates[v][cur][0].close', Fx::opening( $c, V, 'LCL' ), 10 );
$c2 = $c;
unset( $c2['rates'][ V ]['LCL'][0] );
$c2['rates']['real_2026']['LCL'][12]['close'] = 9.5;
near( 'saldo inicial: cierre de dic de real_<año−1>', Fx::opening( $c2, V, 'LCL' ), 9.5 );
unset( $c2['rates']['real_2026'] );
near( 'saldo inicial: cierre del mes 1', Fx::opening( $c2, V, 'LCL' ), 10 );
near( 'promedio anual', Fx::annual_avg( $c, VB, 'LCL' ), 8 );
near( 'constante con tasas de otra versión', Fx::constant( 800, $c, VB, 'LCL', 1, true ), 100 );
$thrown = false;
try {
	Fx::rate( $c, V, 'HNL', 1, 'avg' );
} catch ( \InvalidArgumentException $e ) {
	$thrown = str_contains( $e->getMessage(), 'HNL' );
}
check( 'falta tasa → excepción en español', $thrown );

section( 'Validator: archivos limpios' );
$is = validate( ds_aa() );
check( 'AA sin issues', 0 === count( $is ), codes( $is ) );
$is = validate( ds_bb() );
check( 'BB sin issues', 0 === count( $is ), codes( $is ) );
$aa      = ds_aa();
$aa['reported'] = reported_aa();
check( 'AA con reportados correctos sin issues', 0 === count( validate( $aa ) ) );

section( 'Validator: cada regla dispara y respeta su tolerancia' );
$d = ds_aa();
$d['meta']['scale_declared'] = 'miles de LCL';
check( 'FMT_SCALE_DECL', 1 === count( find( validate( $d ), 'FMT_SCALE_DECL', 'AA' ) ) );

$d  = set( ds_aa(), 'pl', '6101', 10, null );
$is = validate( $d );
check( 'DATA_EMPTY con mes y celda', 1 === count( find( $is, 'DATA_EMPTY', 'AA', 10 ) ) && 'K' === find( $is, 'DATA_EMPTY' )[0]['col'], codes( $is ) );

$d         = ds_aa();
$d['pl'][] = [ 'concept' => '6101', 'label' => 'Personal', 'month' => 4, 'value' => 10000.0, 'src' => src( 'resultados', 99 ) ];
check( 'DATA_DUP', 1 === count( find( validate( $d ), 'DATA_DUP', 'AA', 4 ) ) );

$d  = set( ds_aa(), 'pl', 'price:Snacks', 3, 101.0 ); // V×P = 101,000 vs 100,000 (1 %)
$is = validate( $d );
check( 'CALC_VOLPRICE > 0,5 %', 1 === count( find( $is, 'CALC_VOLPRICE', 'AA', 3 ) ), codes( $is ) );
near( 'CALC_VOLPRICE expected = V×P', find( $is, 'CALC_VOLPRICE' )[0]['expected'] ?? null, 101000 );
near( 'CALC_VOLPRICE impact_usd', find( $is, 'CALC_VOLPRICE' )[0]['impact_usd'] ?? null, 100 );
check( 'CALC_VOLPRICE tolera 0,5 %', 0 === count( validate( set( ds_aa(), 'pl', 'price:Snacks', 3, 100.4 ) ) ) );
// Ventas netas infladas: V×P y detalle coinciden → un solo hallazgo (VOLPRICE), no CALC_DETAIL.
$d  = set( set( set( ds_aa(), 'pl', 'net_sales:Snacks', 8, 104000.0 ), 'pl', '4101', 8, 84000.0 ), 'pl', '4111', 8, 20000.0 );
$is = validate( $d );
check( 'VOLPRICE absorbe el CALC_DETAIL de la misma causa', 1 === count( find( $is, 'CALC_VOLPRICE', 'AA', 8 ) ) && 0 === count( find( $is, 'CALC_DETAIL' ) ), codes( $is ) );

$d  = set( ds_aa(), 'pl', '4101', 5, 79000.0 );
$is = validate( $d );
check( 'CALC_SPLIT terceros + IC ≠ netas', 1 === count( find( $is, 'CALC_SPLIT', 'AA', 5 ) ), codes( $is ) );
check( 'CALC_SPLIT tolera 1 unidad', 0 === count( find( validate( set( ds_aa(), 'pl', '4101', 5, 79999.5 ) ), 'CALC_SPLIT' ) ) );

$d                       = ds_aa();
$d['sales'][2]['amount'] = 81000.0; // mes 2, C1
$is                      = validate( $d );
check( 'CALC_DETAIL detalle ≠ netas', 1 === count( find( $is, 'CALC_DETAIL', 'AA', 2 ) ), codes( $is ) );
$d['sales'][2]['amount'] = 80050.0; // 0,05 %
check( 'CALC_DETAIL tolera 0,1 %', 0 === count( find( validate( $d ), 'CALC_DETAIL' ) ) );
$d = ds_aa();
foreach ( [ 7, 8, 9 ] as $m ) {
	$d['sales'][ 2 * ( $m - 1 ) ]['amount']     = 70000.0;
	$d['sales'][ 2 * ( $m - 1 ) + 1 ]['amount'] = 30000.0; // C4 30,000 vs 4111 20,000
}
$is = validate( $d );
$c4 = array_values( array_filter( find( $is, 'CALC_DETAIL' ), fn( $i ) => '4111' === $i['concept'] ) );
check( 'CALC_DETAIL canal C4 ≠ ventas IC, un issue por línea', 1 === count( $c4 ) && 7 === $c4[0]['month'] && str_contains( $c4[0]['message'], 'jul a sep' ), codes( $is ) );

$d         = ds_aa();
$d['pl'][] = [ 'concept' => 'total_row', 'label' => 'Total Gastos', 'month' => 5, 'value' => 15000.0, 'src' => src( 'resultados', 40, 'C' ) ];
$d['pl'][] = [ 'concept' => 'total_row', 'label' => 'Total Gastos', 'month' => 6, 'value' => 10000.0, 'src' => src( 'resultados', 41, 'C' ) ];
$is        = validate( $d );
check( 'CALC_TOTAL solo donde no cuadra', 1 === count( find( $is, 'CALC_TOTAL', 'AA', 5 ) ) && 0 === count( find( $is, 'CALC_TOTAL', 'AA', 6 ) ), codes( $is ) );
near( 'CALC_TOTAL expected = suma gastos operativos', find( $is, 'CALC_TOTAL' )[0]['expected'] ?? null, 10000 );
$d         = set( ds_aa(), 'pl', '6103', 7, -2000.0 );
$d['pl'][] = [ 'concept' => 'total_row', 'label' => 'Total Gastos', 'month' => 7, 'value' => 12000.0, 'src' => src( 'resultados', 42, 'C' ) ];
$is        = validate( $d );
$sg        = find( $is, 'SIGN', 'AA', 7 );
check( 'SIGN aviso por concepto', 1 === count( $sg ) && 'aviso' === $sg[0]['severity'], codes( $is ) );
check( 'total sumado en positivo: CALC_TOTAL lo explica y SIGN lo menciona', 1 === count( find( $is, 'CALC_TOTAL', 'AA', 7 ) ) && str_contains( find( $is, 'CALC_TOTAL' )[0]['message'], '6103' ) && str_contains( $sg[0]['message'], 'Total Gastos' ) );

$d  = set( ds_aa(), 'bs', '3101', 6, 790000.0 ); // +100 vs 790,000? (600,000 + 180,000 = 780,000)
$is = validate( $d );
check( 'CALC_BS en el mes', 1 === count( find( $is, 'CALC_BS', 'AA', 6 ) ), codes( $is ) );
check( 'CALC_BS tolera 0,1 %', 0 === count( find( validate( set( ds_aa(), 'bs', '3101', 6, 780500.0 ) ), 'CALC_BS' ) ) );

$d  = set( ds_aa(), 'bs', '1502', 9, 146000.0 ); // Δ sep 1,000 y oct 9,000 vs 5,000
$d  = set( $d, 'bs', '1101', 9, 120000 + 35000 * 9 + 1000 ); // mantener el balance cuadrado
$is = validate( $d );
check( 'CALC_DEPR aviso en meses afectados', 2 === count( find( $is, 'CALC_DEPR', 'AA' ) ) && 'aviso' === find( $is, 'CALC_DEPR' )[0]['severity'], codes( $is ) );

$d  = set( ds_aa(), 'pl', '6101', 9, 10.0 );
$is = validate( $d );
$j  = find( $is, 'FMT_SCALE_JUMP', 'AA', 9 );
check( 'FMT_SCALE_JUMP mes ×1000', 1 === count( $j ), codes( $is ) );
near( 'FMT_SCALE_JUMP expected = ×1000', $j[0]['expected'] ?? null, 10000 );
$d = ds_aa();
foreach ( [ '1201', '1301', '1501', '1502', '2101', '3101', '1101' ] as $k ) {
	foreach ( $d['bs'] as &$r ) {
		if ( $r['concept'] === $k && 4 === $r['month'] ) {
			$r['value'] *= 1000;
		}
	}
	unset( $r );
}
$is = validate( $d );
check( 'mes completo fuera de escala → un solo hallazgo, sin CALC_DEPR derivado', 1 === count( find( $is, 'FMT_SCALE_JUMP', 'AA', 4 ) ) && 0 === count( find( $is, 'CALC_DEPR' ) ), codes( $is ) );
$other = ds_aa( 'ppto_2027_v2' );
foreach ( [ 'pl', 'bs' ] as $part ) {
	foreach ( $other[ $part ] as &$r ) {
		$r['value'] = str_contains( (string) $r['concept'], ':' ) ? $r['value'] : $r['value'] * 1000;
	}
	unset( $r );
}
$is = validate( ds_aa(), [ 'ppto_2027_v2' => $other ] );
check( 'FMT_SCALE_JUMP contra otra versión del país (archivo completo)', 1 === count( find( $is, 'FMT_SCALE_JUMP', 'AA' ) ), codes( $is ) );

$d             = $aa;
$d['reported']['fx_used'] = 10.6; // 6 %
$is            = validate( $d );
check( 'FX_USED crítico > 0,5 %', 1 === count( find( $is, 'FX_USED', 'AA' ) ) && 'critico' === find( $is, 'FX_USED' )[0]['severity'] && 'tipo_cambio' === find( $is, 'FX_USED' )[0]['category'] );
$d['reported']['fx_used'] = 10.04;
check( 'FX_USED tolera 0,5 %', 0 === count( find( validate( $d ), 'FX_USED' ) ) );
$d = ds_aa();
foreach ( $d['ic'] as &$l ) {
	if ( 'product_sale' === $l['type'] ) {
		$l['currency'] = 'LCL';
		$l['amount']   = 20000.0;
	}
}
unset( $l );
$is = validate( $d );
check( 'FX_IC_CURRENCY aviso agrupado', 1 === count( find( $is, 'FX_IC_CURRENCY', 'AA' ) ) && 'aviso' === find( $is, 'FX_IC_CURRENCY' )[0]['severity'], codes( $is ) );
$d                        = $aa;
$d['reported']['wacc_pct'] = 13.0;
check( 'KPI_WACC', 1 === count( find( validate( $d ), 'KPI_WACC', 'AA' ) ) );
$r0 = [ 'code' => 'CAT_UNKNOWN', 'company' => 'AA', 'version' => V, 'sheet' => 'resultados', 'row' => 9, 'col' => 'A', 'month' => null, 'concept' => null ];
$d  = ds_aa();
$d['issues'][] = $r0 + [ 'severity' => 'critico', 'category' => 'catalogo_periodo', 'message' => 'x' ];
check( 'no repite issues de lectura', 0 === count( validate( $d ) ) );

section( 'Intercompany' );
$dss = [ 'AA' => ds_aa(), 'BB' => ds_bb() ];
$rec = Intercompany::reconcile( $dss, corp(), V );
check( '24 pares que cuadran y sin issues', 24 === count( $rec['pairs'] ) && 0 === count( $rec['issues'] ) && ! array_filter( $rec['pairs'], fn( $p ) => 'cuadra' !== $p['status'] ), codes( $rec['issues'] ) );
check( 'contraparte por nombre («Meridia Beta»)', 1 === count( array_filter( $rec['pairs'], fn( $p ) => 'management_fee' === $p['type'] && 1 === $p['month'] && 'AA' === $p['from'] && 'BB' === $p['to'] ) ) );
check( 'resultados IC cuadran con la hoja intercompania', ! array_filter( $rec['pl_tie'], fn( $x ) => ! $x['ok'] ) && count( $rec['pl_tie'] ) > 0 );
$b                 = ds_bb();
$b['ic'][10]['amount'] = 1900.0; // compra de junio 1,900 vs 2,000 (5 %)
$rec               = Intercompany::reconcile( [ 'AA' => ds_aa(), 'BB' => $b ], corp(), V );
check( 'IC_MISMATCH uno por empresa', 2 === count( find( $rec['issues'], 'IC_MISMATCH', null, 6 ) ) && 1 === count( find( $rec['issues'], 'IC_MISMATCH', 'BB' ) ), codes( $rec['issues'] ) );
near( 'IC_MISMATCH impacto USD', find( $rec['issues'], 'IC_MISMATCH' )[0]['impact_usd'] ?? null, 100 );
$b['ic'][10]['amount'] = 1995.0;
check( 'tolerancia 0,5 %', 0 === count( Intercompany::reconcile( [ 'AA' => ds_aa(), 'BB' => $b ], corp(), V )['issues'] ) );
$b       = ds_bb();
$b['ic'] = array_values( array_filter( $b['ic'], fn( $l ) => ! ( 'product_sale' === $l['type'] && $l['month'] >= 7 ) ) );
$rec     = Intercompany::reconcile( [ 'AA' => ds_aa(), 'BB' => $b ], corp(), V );
$ms      = find( $rec['issues'], 'IC_MISSING' );
check( 'IC_MISSING de 6 meses = un hallazgo por empresa', 2 === count( $ms ) && str_contains( $ms[0]['message'], 'jul a dic' ), codes( $rec['issues'] ) );
check( 'IC_PL_TIE no salta (resultados de AA = su hoja)', 0 === count( find( $rec['issues'], 'IC_PL_TIE' ) ) );
// Diferencia de tiempo por nota «en tránsito» y por compensación al mes siguiente.
$b         = ds_bb();
$b['ic'][] = [ 'month' => 1, 'counterparty' => 'AA', 'type' => 'product_sale', 'line' => 'Snacks', 'direction' => 'purchase', 'amount' => 500.0, 'currency' => 'USD', 'note' => 'Factura de dic-2026 en tránsito', 'src' => src( 'intercompania', 90, 'F' ) ];
$rec       = Intercompany::reconcile( [ 'AA' => ds_aa(), 'BB' => $b ], corp(), V );
check( 'nota «en tránsito» → IC_TIMING aviso', 2 === count( find( $rec['issues'], 'IC_TIMING' ) ) && 'aviso' === find( $rec['issues'], 'IC_TIMING' )[0]['severity'] && 0 === count( find( $rec['issues'], 'IC_MISMATCH' ) ), codes( $rec['issues'] ) );
$b                    = ds_bb();
$b['ic'][4]['amount'] = 1700.0; // mar: 300 menos
$b['ic'][6]['amount'] = 2300.0; // abr: 300 más
$rec                  = Intercompany::reconcile( [ 'AA' => ds_aa(), 'BB' => $b ], corp(), V );
check( 'compensación en el mes siguiente → tiempo', 4 === count( find( $rec['issues'], 'IC_TIMING' ) ) && 0 === count( find( $rec['issues'], 'IC_MISMATCH' ) ), codes( $rec['issues'] ) );
$b                      = ds_bb();
$b['ic'][8]['currency'] = 'LCL'; // mayo: 2,000 LCL = 200 USD
$rec                    = Intercompany::reconcile( [ 'AA' => ds_aa(), 'BB' => $b ], corp(), V );
$mm                     = find( $rec['issues'], 'IC_MISMATCH', 'BB', 5 );
check( 'moneda pactada distinta → se compara en USD', 1 === count( $mm ) && str_contains( $mm[0]['message'], 'LCL' ), codes( $rec['issues'] ) );
$a  = set( ds_aa(), 'pl', '4901', 3, 12000.0 );
$rec = Intercompany::reconcile( [ 'AA' => $a, 'BB' => ds_bb() ], corp(), V );
check( 'IC_PL_TIE: cuota en resultados ≠ hoja', 1 === count( find( $rec['issues'], 'IC_PL_TIE', 'AA', 3 ) ), codes( $rec['issues'] ) );
check( 'IC_PL_TIE se omite si el mes tiene FMT_DATE', 0 === count( find( Intercompany::reconcile( [ 'AA' => $a, 'BB' => ds_bb() ], corp(), V, [ [ 'code' => 'FMT_DATE', 'company' => 'AA', 'sheet' => 'resultados', 'month' => 3 ] ] )['issues'], 'IC_PL_TIE' ) ) );
$b  = set( ds_bb(), 'bs', '2102', 12, 1500.0 );
$rec = Intercompany::reconcile( [ 'AA' => ds_aa(), 'BB' => $b ], corp(), V );
check( 'IC_BALANCE cxc vs cxp a cierre', 1 === count( find( $rec['issues'], 'IC_BALANCE', Intercompany::GROUP_COMPANY, 12 ) ), codes( $rec['issues'] ) );
// Préstamo AA → BB por par.
$a = ds_aa();
$b = ds_bb();
$a['ic'][] = [ 'month' => 1, 'counterparty' => 'BB', 'type' => 'loan', 'line' => null, 'direction' => 'granted', 'amount' => 5000.0, 'currency' => 'USD', 'note' => null, 'src' => src( 'intercompania', 80, 'F' ) ];
$b['ic'][] = [ 'month' => 1, 'counterparty' => 'AA', 'type' => 'loan', 'line' => null, 'direction' => 'received', 'amount' => 5000.0, 'currency' => 'USD', 'note' => null, 'src' => src( 'intercompania', 80, 'F' ) ];
for ( $m = 1; $m <= 12; $m++ ) {
	$a['bs'][] = [ 'concept' => '1401', 'label' => '1401', 'month' => $m, 'value' => 50000.0, 'src' => src( 'balance', 20, 'C' ) ];
	$b['bs'][] = [ 'concept' => '2301', 'label' => '2301', 'month' => $m, 'value' => 12 === $m ? 4000.0 : 5000.0, 'src' => src( 'balance', 20, 'C' ) ];
}
$rec = Intercompany::reconcile( [ 'AA' => $a, 'BB' => $b ], corp(), V );
check( 'préstamo por par prestamista → deudor', 2 === count( find( $rec['issues'], 'IC_BALANCE', null, 12 ) ) && 1 === count( find( $rec['issues'], 'IC_BALANCE', 'AA' ) ) && 1 === count( find( $rec['issues'], 'IC_BALANCE', 'BB' ) ), codes( $rec['issues'] ) );

section( 'Consolidator' );
$res = Consolidator::run( [ 'AA' => ds_aa(), 'BB' => ds_bb() ], corp(), V );
$L   = [];
foreach ( $res['lines'] as $l ) {
	$L[ $l['account'] ] = $l;
}
near( 'AA ventas terceros a USD (avg 10)', $L['4101']['by_company']['AA'], 96000 );
near( 'ventas terceros consolidadas', $L['4101']['total'], 216000 );
near( 'ventas IC eliminadas', $L['4111']['total'], 0 );
near( 'eliminación ventas IC', $L['4111']['eliminations'], -24000 );
near( 'costo − compra IC', $L['5101']['total'], 120000 );
near( 'cuota: ingreso eliminado', $L['4901']['total'], 0 );
near( 'cuota: gasto eliminado', $L['6105']['total'], 0 );
near( 'margen no realizado del año (5901)', $L['5901']['total'], 480 );
near( 'margen por mes (5901, ene)', $L['5901']['months'][1], 40 );
near( '1302 menos margen al cierre', $L['1302']['close'], 2200 - 880 );
near( '1302 menos margen al inicio', $L['1302']['open'], 1000 - 400 );
near( 'cxc IC eliminada', $L['1202']['close'], 0 );
near( 'cxp IC eliminada', $L['2102']['close'], 0 );
near( 'EBIT consolidado', $res['totals']['ebit'], 59520 );
near( 'ingresos operativos consolidados', $res['totals']['revenue'], 216000 );
near( 'utilidad neta', $res['totals']['net_income'], 47520 );
near( 'minoritarios: resultado 20 % de BB', $res['minority']['income'], 2400 );
near( 'minoritarios: patrimonio (3102)', $L['3102']['close'], 8400 );
near( 'utilidad atribuible al grupo', $res['totals']['net_income_group'], 45120 );
near( 'EBIT por país (AA)', $res['by_company']['AA']['ebit'], 42000 );
near( 'EBIT local (AA)', $res['by_company']['AA']['local']['ebit'], 420000 );
near( 'IC sin conciliar = 0', $res['ic_unreconciled'], 0 );
$bal = function ( array $res, int $m ): float {
	$a = 0.0;
	$le = 0.0;
	foreach ( $res['lines'] as $l ) {
		if ( 'BS' === $l['statement'] ) {
			'activo' === $l['type'] ? $a += $l['sign'] * $l['months'][ $m ] : $le += $l['months'][ $m ];
		}
	}
	return $a - $le;
};
near( 'balance consolidado cuadra al cierre', $bal( $res, 12 ), 0 );
near( 'balance consolidado cuadra al inicio', $bal( $res, 0 ), 0 );
$src = $L['4101']['sources'][0];
check( 'traza: empresa, archivo, hoja, fila, columna, mes, local, tasa, usd', 'AA' === $src['company'] && 'Alfa.xlsx' === $src['file'] && 'resultados' === $src['sheet'] && 1 === $src['month'] && 80000.0 === $src['local'] && 10.0 === $src['rate'] && 8000.0 === $src['usd'] && 'B' === $src['col'] );
near( 'CTA = 0 con tasa constante', $res['cta']['AA']['cta'], 0 );
$c3 = corp();
$c3['rates'][ V ]['LCL'][12]['close'] = 12.5;
$r3 = Consolidator::run( [ 'AA' => ds_aa(), 'BB' => ds_bb() ], $c3, V );
near( 'CTA con cierre 12.5', $r3['cta']['AA']['cta'], 960000 / 12.5 - ( 60000 + 36000 ) );
near( 'balance cuadra con CTA', $bal( $r3, 12 ), 0 );
$b                     = ds_bb();
$b['ic'][10]['amount'] = 1900.0;
$r4                    = Consolidator::run( [ 'AA' => ds_aa(), 'BB' => $b ], corp(), V );
near( 'no cuadra: se elimina el lado vendedor', array_values( array_filter( $r4['lines'], fn( $l ) => '4111' === $l['account'] ) )[0]['eliminations'], -24000 );
near( 'no cuadra: diferencia a ic_unreconciled', $r4['ic_unreconciled'], 100 );

section( 'Kpi' );
$k  = Kpi::compute( $res, corp() );
$ka = $k['companies']['AA'];
near( 'NOPAT local', $ka['local']['nopat'], 315000 );
near( 'CTON promedio (inicial + final) / 2', $ka['local']['nowc'], 80000 );
near( 'activo fijo neto promedio', $ka['local']['nfa'], 370000 );
near( 'CI local', $ka['local']['ci'], 450000 );
near( 'CE local', $ka['local']['ce'], 780000 );
near( 'ROIC', $ka['roic'], 0.7, 1e-9 );
near( 'ROCE', $ka['roce'], 420000 / 780000, 1e-9 );
near( 'EVA USD', $ka['eva'], 26550 );
near( 'EVA local', $ka['local']['eva'], 265500 );
near( 'CI BB (USD)', $k['companies']['BB']['ci'], 17600 );
$g = $k['group'];
near( 'grupo EBIT consolidado', $g['ebit'], 59520 );
near( 'grupo NOPAT (tasa ponderada por EBIT)', $g['nopat'], 59520 * ( 1 - 14280 / 60000 ) );
near( 'grupo CI del balance consolidado', $g['ci'], 61960 );
near( 'grupo WACC ponderado por CI', $g['wacc'], ( 0.11 * 45000 + 0.09 * 17600 ) / 62600, 1e-9 );
check( 'semáforo verde sin críticos', 'verde' === $ka['light'] && $ka['reasons'] );
$kc = Kpi::compute( $res, corp(), [ [ 'company' => 'AA', 'severity' => 'critico' ], [ 'company' => 'BB', 'severity' => 'critico', 'status' => 'corregida' ] ] );
check( 'crítico abierto → rojo', 'rojo' === $kc['companies']['AA']['light'] && 'verde' === $kc['companies']['BB']['light'] && 'rojo' === $kc['group']['light'] );
check( 'semáforo: verde / amarillo / rojo', 'verde' === Kpi::light( 0.12, 0.105, 0 )[0] && 'verde' === Kpi::light( 0.115, 0.105, 0 )[0] && 'amarillo' === Kpi::light( 0.11, 0.105, 0 )[0] && 'rojo' === Kpi::light( 0.09, 0.105, 0 )[0] && 'amarillo' === Kpi::light( null, 0.1, 0 )[0] );
$cp = Kpi::copied( $aa, $ka, [ 'corporate' => corp(), 'version' => V ] );
check( 'copiados que cuadran: 0 issues', 0 === count( $cp['issues'] ) && count( $cp['rows'] ) >= 9, codes( $cp['issues'] ) );
$d                          = $aa;
$d['reported']['roic_pct'] = 60.0;
$cp                         = Kpi::copied( $d, $ka, [ 'corporate' => corp(), 'version' => V ] );
check( 'KPI_COPY_ROIC solo el indicador mal copiado', [ 'KPI_COPY_ROIC' ] === array_column( $cp['issues'], 'code' ) && 8 === $cp['issues'][0]['row'], codes( $cp['issues'] ) );
$d                          = $aa;
$d['reported']['ic_avg']    = 500000.0;
$d['reported']['roic_pct']  = 63.0;
$d['reported']['eva_local'] = 260000.0;
$d['reported']['eva_usd']   = 26000.0;
$cp                         = Kpi::copied( $d, $ka, [ 'corporate' => corp(), 'version' => V ] );
check( 'CI mal: ROIC y EVA derivados no se cuentan otra vez', [ 'KPI_COPY_CI' ] === array_column( $cp['issues'], 'code' ), codes( $cp['issues'] ) );
$d                     = $aa;
$d['reported']['ebit'] = 410000.0;
$d['reported']['nopat'] = 307500.0;
$d['reported']['roic_pct'] = 68.3;
$d['reported']['roce_pct'] = 52.6;
$d['reported']['eva_local'] = 258000.0;
$d['reported']['eva_usd'] = 25800.0;
$d['issues'][]         = [ 'code' => 'CAT_UNKNOWN', 'sheet' => 'resultados', 'actual' => 10000.0 ];
$cp                    = Kpi::copied( $d, $ka, [ 'corporate' => corp(), 'version' => V ] );
check( 'EBIT que difiere justo por una cuenta sin mapeo (CAT_UNKNOWN) no se marca', 0 === count( $cp['issues'] ) && 'CAT_UNKNOWN' === $cp['rows'][0]['explained_by'], codes( $cp['issues'] ) );
$pr = Kpi::proposals( corp() );
check( 'propuesta que se sostiene', 'aprobar' === $pr[0]['decision_recalculada'] && $pr[0]['holds'] && $pr[0]['wacc_ok'] && $pr[0]['capital_ok'] && $pr[0]['eva_ok'] );
check( 'propuesta con WACC equivocado y capital < inversión', ! $pr[1]['wacc_ok'] && ! $pr[1]['capital_ok'] && 'ajustar' === $pr[1]['decision_recalculada'] && ! $pr[1]['holds'], json_encode( $pr[1] ) );
near( 'EVA recalculado con el WACC real', $pr[1]['eva'], 100 - 0.09 * 950 );
$imp = Kpi::issue_impact( [ 'code' => 'CALC_TOTAL', 'company' => 'AA', 'version' => V, 'concept' => '6101', 'month' => 3, 'actual' => 10000.0, 'expected' => 12000.0 ], $ka, [ 'corporate' => corp() ] );
near( 'issue_impact: ΔEBIT de un gasto', $imp['delta_ebit'], -200 );
near( 'issue_impact: puntos de ROIC', $imp['points'], -150 / 45000 * 100, 1e-9 );

section( 'Variance' );
$rb = Consolidator::run( [ 'AA' => ds_aa( VB, 900, 74000, 8.0 ), 'BB' => ds_bb( VB ) ], corp(), VB );
$v  = Variance::compare( $res, $rb, [ 'corporate' => corp() ] );
$va = $v['companies']['AA'];
near( 'AA Δ ventas USD', $va['delta'], 12 * ( 10000 - 90000 / 8 ) );
near( 'AA volumen', $va['effects']['volumen'], 12 * 100 * 100 / 8 );
near( 'AA precio', $va['effects']['precio'], 0 );
near( 'AA tipo de cambio', $va['effects']['tipo_cambio'], 12 * 100000 * ( 1 / 10 - 1 / 8 ) );
near( 'AA otros', $va['effects']['otros'], 0 );
foreach ( array_merge( [ 'grupo' => $v['group'] ], $v['companies'], $v['group']['lines'] ) as $who => $x ) {
	near( "efectos suman exacto ({$who})", array_sum( $x['effects'] ), $x['delta'] );
}
near( 'grupo intercompañía (sin cambio)', $v['group']['effects']['intercompania'], 0 );
check( 'texto en español con los 2 efectos mayores', 'Las ventas de AA bajan 11.1 %: 22.2 puntos por tipo de cambio en contra y 11.1 por más volumen.' === $va['text'], $va['text'] );
$ok = true;
foreach ( $v['accounts'] as $acc ) {
	$ok = $ok && abs( $acc['a_tipo_constante'] + $acc['tipo_cambio'] + $acc['eliminaciones'] + $acc['otros'] - $acc['delta'] ) < 0.01 && abs( $acc['otros'] ) < 0.01;
}
check( 'cuentas: tipo constante + tipo de cambio + eliminaciones = Δ', $ok );

section( 'History' );
$q = History::quality( [ 'AA' => [ [ 'category' => 'formato_escala', 'severity' => 'critico' ], [ 'category' => 'intercompania', 'severity' => 'critico' ] ] ], corp() );
check( 'calidad: conteo por categoría y tendencia', 1 === $q['countries']['AA']['current']['formato_escala'] && 2 === $q['countries']['AA']['total'] && 'mejora' === $q['countries']['AA']['trend'] && 'formato_escala' === $q['countries']['AA']['top_category'] && $q['countries']['AA']['rule'] );
check( 'ranking', 'AA' === $q['ranking'][0] );
$t = History::kpi_trend( corp(), [ V => $k ] );
check( 'serie evolutiva: historial + versión', 2 === count( $t['AA'] ) && '2025 real' === $t['AA'][0]['label'] && isset( $t['GRUPO'] ) );
$s = History::scenarios( $res, [ 'corporate' => corp(), 'kpi' => $k ] );
near( 'escenario TC +10 %: ROIC de AA igual', $s['companies']['AA']['tc_mas_10']['roic'], 0.7, 1e-9 );
near( 'escenario TC +10 %: EVA de AA ÷ 1.1', $s['companies']['AA']['tc_mas_10']['eva'], 26550 / 1.1 );
near( 'escenario volumen +10 %: EBIT de AA + 10 % del margen bruto', $s['companies']['AA']['volumen_mas_10']['ebit'], 42000 + 0.1 * ( 120000 - 72000 ) );

/* ====================================================================== (b) integración con datos-meridia */

section( 'Integración con datos-meridia' );
$root    = dirname( __DIR__ );
$data    = getenv( 'CSL_DATA' ) ?: dirname( $root ) . '/datos-meridia';
$engine  = $root . '/includes/Engine';
$present = is_file( $engine . '/CountryReader.php' ) && is_file( $engine . '/Corporate.php' ) && is_file( $engine . '/Profiles.php' ) && is_file( $data . '/2_Grupo/Grupo_Corporativo.xlsx' );
if ( ! $present ) {
	echo "  (aviso) se omite: faltan CountryReader/Corporate/Profiles o la carpeta {$data}\n";
} else {
	integration( $data, __DIR__ . '/hallazgos.md' );
}

/* ====================================================================== resultado */

$fails = $GLOBALS['t_fail'];
echo "\n" . $GLOBALS['t_pass'] . ' pruebas correctas, ' . count( $fails ) . " fallidas.\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( $fails ? 1 : 0 );

/* ====================================================================== integración */

function integration( string $data, string $out_file ): void {
	$corp     = \Consolida\Engine\Corporate::load( $data . '/2_Grupo/Grupo_Corporativo.xlsx' );
	$profiles = \Consolida\Engine\Profiles::defaults();
	$files    = glob( $data . '/1_Paises/*/*.xlsx' );
	check( '20 archivos de país', 20 === count( $files ), (string) count( $files ) );
	$all = [];
	foreach ( $files as $f ) {
		$code = \Consolida\Engine\Profiles::detect( basename( $f ), $profiles );
		$ds   = \Consolida\Engine\CountryReader::read( $f, $profiles[ $code ], $corp );
		$all[ $ds['meta']['version'] ][ $code ] = $ds;
	}
	$versions = array_keys( Support::VERSION_LABELS );
	check( '4 versiones × 5 países', array_keys( array_intersect_key( array_flip( $versions ), $all ) ) === $versions && 5 === count( $all['ppto_2027_v1'] ?? [] ) );
	$by_company = [];
	foreach ( $all as $v => $dss ) {
		foreach ( $dss as $c => $ds ) {
			$by_company[ $c ][ $v ] = $ds;
		}
	}
	$issues = [];
	$res    = [];
	$kpis   = [];
	$copied = [];
	foreach ( $versions as $v ) {
		$dss = $all[ $v ];
		$iss = [];
		foreach ( $dss as $c => $ds ) {
			$iss = array_merge( $iss, $ds['issues'], Validator::validate( $ds, [ 'corporate' => $corp, 'version' => $v, 'others' => $by_company[ $c ] ] ) );
		}
		$ic           = Intercompany::reconcile( $dss, $corp, $v, $iss );
		$iss          = array_merge( $iss, $ic['issues'] );
		$res[ $v ]    = Consolidator::run( $dss, $corp, $v, $ic );
		$kpis[ $v ]   = Kpi::compute( $res[ $v ], $corp );
		foreach ( $dss as $c => $ds ) {
			$cp                 = Kpi::copied( $ds, $kpis[ $v ]['companies'][ $c ], [ 'corporate' => $corp, 'version' => $v ] );
			$copied[ $v ][ $c ] = $cp['rows'];
			$iss                = array_merge( $iss, $cp['issues'] );
		}
		$issues[ $v ] = $iss;
		// Balance consolidado cuadrado.
		$a  = 0.0;
		$le = 0.0;
		foreach ( $res[ $v ]['lines'] as $l ) {
			if ( 'BS' === $l['statement'] ) {
				'activo' === $l['type'] ? $a += $l['sign'] * $l['close'] : $le += $l['close'];
			}
		}
		check( "{$v}: balance consolidado cuadra", abs( $a - $le ) <= 0.0001 * abs( $a ), sprintf( '%.2f vs %.2f', $a, $le ) );
		check( "{$v}: issues con categoría del historial", ! array_filter( $iss, fn( $i ) => ! isset( Support::CATEGORIES[ $i['category'] ?? '' ] ) ) );
	}
	foreach ( [ 'ppto_2026', 'real_2026', 'ppto_2027_v2' ] as $v ) {
		$crit = array_filter( $issues[ $v ], fn( $i ) => Support::CRITICAL === $i['severity'] );
		check( "{$v}: versión limpia sin críticos", 0 === count( $crit ), codes( $crit ) );
	}
	$v1     = $issues['ppto_2027_v1'];
	$expect = [
		[ 'MX', 'FMT_SCALE_JUMP', 4, 'balance de abril en unidades' ],
		[ 'EC', 'FMT_SCALE_JUMP', 9, '8101 de septiembre en miles' ],
		[ 'EC', 'CALC_TOTAL', 5, 'Total Gastos de mayo' ],
		[ 'EC', 'CALC_TOTAL', 7, 'Total Gastos de julio' ],
		[ 'EC', 'DATA_DUP', 8, '7220 duplicado' ],
		[ 'GT', 'CALC_VOLPRICE', 8, 'Bebidas agosto' ],
		[ 'PA', 'CALC_DETAIL', 11, 'noviembre ventas_detalle' ],
		[ 'PA', 'CALC_BS', 9, 'balance de septiembre' ],
		[ 'US', 'IC_MISSING', null, 'Confitería a MX' ],
		[ 'US', 'IC_MISMATCH', 5, 'compra en USD' ],
		[ 'US', 'CALC_DETAIL', null, 'canal C4 Confitería' ],
		[ 'EC', 'IC_TIMING', 1, 'compra en tránsito' ],
		[ 'MX', 'FX_USED', null, 'tipo de cambio 18.5' ],
		[ 'EC', 'KPI_WACC', null, 'WACC 11 %' ],
		[ 'GT', 'KPI_COPY_ROIC', null, 'ROIC copiado' ],
	];
	foreach ( $expect as [ $c, $code, $m, $what ] ) {
		check( "v1 encuentra {$c} {$code} ({$what})", count( find( $v1, $code, $c, $m ) ) > 0 );
	}
	check( 'v2 también marca la compra en tránsito de EC como aviso', 1 === count( find( $issues['ppto_2027_v2'], 'IC_TIMING', 'EC', 1 ) ) && 'aviso' === find( $issues['ppto_2027_v2'], 'IC_TIMING', 'EC', 1 )[0]['severity'] );
	$var = [];
	foreach ( [ [ 'ppto_2027_v2', 'ppto_2027_v1' ], [ 'ppto_2027_v1', 'real_2026' ], [ 'real_2026', 'ppto_2026' ] ] as [ $a, $b ] ) {
		$x   = Variance::compare( $res[ $a ], $res[ $b ], [ 'corporate' => $corp ] );
		$err = abs( array_sum( $x['group']['effects'] ) - $x['group']['delta'] );
		foreach ( array_merge( $x['companies'], $x['group']['lines'] ) as $cc ) {
			$err = max( $err, abs( array_sum( $cc['effects'] ) - $cc['delta'] ) );
			foreach ( $cc['lines'] ?? [] as $ln ) {
				$err = max( $err, abs( array_sum( $ln['effects'] ) - $ln['delta'] ) );
			}
		}
		check( "variance {$a} vs {$b}: efectos suman exacto", $err <= 0.01, (string) $err );
		$var[ "{$a} vs {$b}" ] = $x;
	}
	write_findings( $out_file, $corp, $issues, $res, $kpis, $copied, $var );
	check( 'tests/hallazgos.md escrito', is_file( $out_file ) && filesize( $out_file ) > 1000 );
}

/** Escribe tests/hallazgos.md. */
function write_findings( string $file, array $corp, array $issues, array $res, array $kpis, array $copied, array $var ): void {
	$e   = fn( $s ) => str_replace( [ '|', "\n" ], [ '\\|', ' ' ], (string) $s );
	$cat = Support::CATEGORIES;
	$o   = [];
	$o[] = '# Hallazgos del motor sobre los datos reales del Grupo Meridia';
	$o[] = '';
	$o[] = 'Generado por `php tests/run.php` el ' . gmdate( 'Y-m-d H:i' ) . ' UTC con los 21 archivos de `datos-meridia/`. Lectura (Agente R) + reglas, conciliación, consolidación, KPIs y desviaciones (Agente F). Impacto en USD (valor absoluto).';
	$o[] = '';
	// Resumen.
	$o[] = '## Resumen por versión y categoría';
	$o[] = '';
	$o[] = '| Versión | Críticos | Avisos | ' . implode( ' | ', $cat ) . ' |';
	$o[] = '| --- | ---: | ---: | ' . implode( ' | ', array_fill( 0, count( $cat ), '---:' ) ) . ' |';
	foreach ( $issues as $v => $iss ) {
		$n   = Support::count( $iss );
		$row = [];
		foreach ( array_keys( $cat ) as $k ) {
			$row[] = count( array_filter( $iss, fn( $i ) => $i['category'] === $k ) );
		}
		$o[] = '| ' . Support::version_label( $v ) . ' | ' . $n['critico'] . ' | ' . $n['aviso'] . ' | ' . implode( ' | ', $row ) . ' |';
	}
	$o[] = '';
	// Comparación con historial.
	$o[] = '## Presupuesto 2027 v1 frente al historial (errores por categoría)';
	$o[] = '';
	$by  = [];
	foreach ( $issues['ppto_2027_v1'] as $i ) {
		$by[ $i['company'] ][] = $i;
	}
	$q   = History::quality( $by, $corp );
	$o[] = '| País | ' . implode( ' | ', $cat ) . ' | Total 2027 v1 | Total Ppto 2026 (historial) | Tendencia | Lo que más se repite |';
	$o[] = '| --- | ' . implode( ' | ', array_fill( 0, count( $cat ), '---:' ) ) . ' | ---: | ---: | --- | --- |';
	foreach ( $q['countries'] as $c => $r ) {
		$o[] = '| ' . $c . ' | ' . implode( ' | ', $r['current'] ) . ' | ' . $r['total'] . ' | ' . ( $r['last_total'] ?? '—' ) . ' | ' . $r['trend'] . ' | ' . ( $cat[ $r['top_category'] ] ?? '' ) . ' |';
	}
	$o[] = '';
	$o[] = 'Nota: el historial cuenta errores por país; aquí cada hallazgo intercompañía se cuenta en las dos empresas implicadas, y «GRUPO» agrupa los saldos intercompañía que no se pueden atribuir a un país.';
	$o[] = '';
	// Detalle.
	foreach ( $issues as $v => $iss ) {
		$o[] = '## ' . Support::version_label( $v ) . ' (`' . $v . '`)';
		$o[] = '';
		if ( ! $iss ) {
			$o[] = 'Sin hallazgos.';
			$o[] = '';
			continue;
		}
		$by = [];
		foreach ( $iss as $i ) {
			$by[ $i['company'] ][] = $i;
		}
		ksort( $by );
		foreach ( $by as $c => $list ) {
			usort( $list, fn( $a, $b ) => [ $a['severity'] === 'critico' ? 0 : 1, $a['category'], $a['month'] ?? 0 ] <=> [ $b['severity'] === 'critico' ? 0 : 1, $b['category'], $b['month'] ?? 0 ] );
			$o[] = '### ' . $c . ' · ' . count( $list ) . ' hallazgos';
			$o[] = '';
			$o[] = '| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |';
			$o[] = '| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |';
			foreach ( $list as $i ) {
				$o[] = sprintf( '| %s | %s | %s | %s | %s | %s | %s | %s | %s |', $i['code'], $i['severity'], $i['category'], $i['sheet'] ?? '', $i['row'] ?? '', $i['col'] ?? '',
					null === $i['month'] ? '' : Support::month_name( $i['month'] ), $e( $i['message'] ), null === $i['impact_usd'] ? '' : Support::fmt( $i['impact_usd'], 0 ) );
			}
			$o[] = '';
		}
	}
	// Consolidado y KPIs.
	$o[] = '## Consolidado e indicadores (USD)';
	$o[] = '';
	$o[] = '| Versión | Ingresos | EBIT | Utilidad neta | Atribuible al grupo | Minoritarios | IC sin conciliar | ROIC grupo | WACC grupo | EVA grupo |';
	$o[] = '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |';
	foreach ( $res as $v => $r ) {
		$g   = $kpis[ $v ]['group'];
		$o[] = sprintf( '| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |', Support::version_label( $v ), Support::fmt( $r['totals']['revenue'], 0 ), Support::fmt( $r['totals']['ebit'], 0 ),
			Support::fmt( $r['totals']['net_income'], 0 ), Support::fmt( $r['totals']['net_income_group'], 0 ), Support::fmt( $r['minority']['income'], 0 ), Support::fmt( $r['ic_unreconciled'], 0 ),
			Support::pct( $g['roic'] ), Support::pct( $g['wacc'] ), Support::fmt( $g['eva'], 0 ) );
	}
	$o[] = '';
	$o[] = '| Versión | País | EBIT USD | NOPAT USD | CI USD | ROIC | WACC | EVA USD | Semáforo | CTA USD |';
	$o[] = '| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |';
	foreach ( $kpis as $v => $k ) {
		foreach ( $k['companies'] as $c => $x ) {
			$o[] = sprintf( '| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |', Support::version_label( $v ), $c, Support::fmt( $x['ebit'], 0 ), Support::fmt( $x['nopat'], 0 ), Support::fmt( $x['ci'], 0 ),
				Support::pct( $x['roic'] ), Support::pct( $x['wacc'] ), Support::fmt( $x['eva'], 0 ), $x['light'], Support::fmt( $res[ $v ]['cta'][ $c ]['cta'] ?? 0, 0 ) );
		}
	}
	$o[] = '';
	$o[] = '## Indicadores reportados vs calculados (moneda local)';
	$o[] = '';
	$o[] = '| Versión | País | Indicador | Reportado | Calculado | ¿Cuadra? |';
	$o[] = '| --- | --- | --- | ---: | ---: | --- |';
	foreach ( $copied as $v => $by_c ) {
		foreach ( $by_c as $c => $rows ) {
			foreach ( $rows as $r ) {
				$o[] = sprintf( '| %s | %s | %s | %s | %s | %s |', Support::version_label( $v ), $c, $r['label'], Support::fmt( $r['reported'], 'pct' === $r['unit'] ? 1 : 0 ), Support::fmt( $r['calculated'], 'pct' === $r['unit'] ? 1 : 0 ),
					$r['ok'] ? 'sí' : ( $r['explained_by'] ? 'no (explicado por ' . $r['explained_by'] . ')' : '**no**' ) );
			}
		}
	}
	$o[] = '';
	$o[] = '## Propuestas de inversión recalculadas';
	$o[] = '';
	$o[] = '| Propuesta | Sede | ROIC presentado | ROIC recalculado | WACC sede | EVA recalculado | Presentada | Recalculada | ¿Se sostiene? |';
	$o[] = '| --- | --- | ---: | ---: | ---: | ---: | --- | --- | --- |';
	foreach ( Kpi::proposals( $corp ) as $p ) {
		$o[] = sprintf( '| %s | %s | %s | %s | %s | %s | %s | %s | %s |', $p['id'], $p['site'], Support::pct( $p['roic_presented'], 2 ), Support::pct( $p['roic'], 2 ), Support::pct( $p['wacc'] ), Support::fmt( $p['eva'], 0 ),
			$p['decision_propuesta'], $p['decision_recalculada'], $p['holds'] ? 'sí' : '**no**: ' . $e( implode( ' ', $p['reasons'] ) ) );
	}
	$o[] = '';
	$o[] = '## Desviaciones de ventas (USD)';
	$o[] = '';
	$o[] = '| Comparación | Δ ventas | Volumen | Precio | Tipo de cambio | Intercompañía | Otros | Explicación |';
	$o[] = '| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |';
	foreach ( $var as $label => $x ) {
		$g   = $x['group'];
		$o[] = sprintf( '| %s | %s | %s | %s | %s | %s | %s | %s |', $label, Support::fmt( $g['delta'], 0 ), Support::fmt( $g['effects']['volumen'], 0 ), Support::fmt( $g['effects']['precio'], 0 ),
			Support::fmt( $g['effects']['tipo_cambio'], 0 ), Support::fmt( $g['effects']['intercompania'], 0 ), Support::fmt( $g['effects']['otros'], 0 ), $e( $g['text'] ) );
		foreach ( $x['companies'] as $c => $cc ) {
			$o[] = sprintf( '| %s · %s | %s | %s | %s | %s | | %s | %s |', $label, $c, Support::fmt( $cc['delta'], 0 ), Support::fmt( $cc['effects']['volumen'], 0 ), Support::fmt( $cc['effects']['precio'], 0 ),
				Support::fmt( $cc['effects']['tipo_cambio'], 0 ), Support::fmt( $cc['effects']['otros'], 0 ), $e( $cc['text'] ) );
		}
	}
	$o[] = '';
	file_put_contents( $file, implode( "\n", $o ) );
}
