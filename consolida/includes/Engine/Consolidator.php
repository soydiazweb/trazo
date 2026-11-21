<?php
/**
 * Consolidación mensual del grupo (CONTRACT §10).
 *
 * Por país: cuentas del catálogo a USD por mes (resultados a promedio del mes, balance a cierre del mes, saldo
 * inicial a la tasa de saldo inicial). Eliminaciones con el lado que vende / cobra / presta: ventas de producto,
 * cuota de administración, intereses, saldos por cobrar/pagar, préstamos y margen no realizado del inventario
 * comprado al grupo. Minoritarios (3102 y utilidad atribuible). CTA por país.
 *
 * Las cuentas salen de los roles del catálogo (Support::roles). PHP puro: sin funciones de WordPress.
 */

namespace Consolida\Engine;

final class Consolidator {

	/**
	 * @param array      $datasets  code => Dataset §6 de la versión.
	 * @param array      $corporate Corporate §4.
	 * @param string     $version   clave de versión.
	 * @param array|null $ic        salida de Intercompany::reconcile ya calculada (opcional).
	 */
	public static function run( array $datasets, array $corporate, string $version, ?array $ic = null ): array {
		$catalog = (array) ( $corporate['catalog'] ?? [] );
		if ( ! $catalog ) {
			throw new \InvalidArgumentException( 'Falta el catálogo de cuentas del corporativo para consolidar.' );
		}
		$roles = Support::roles( $corporate );
		$lines = Support::lines( $corporate );
		$ds_by = [];
		foreach ( $datasets as $k => $ds ) {
			$ds_by[ (string) ( $ds['meta']['company'] ?? $k ) ] = $ds;
		}
		$codes = array_keys( $ds_by );

		$usd      = [];   // [acc][code][m]
		$local    = [];   // [acc][code][m]
		$sources  = [];   // [acc][]
		$rates    = [];   // [code] => [m => ['avg'=>,'close'=>]]
		$stats    = [];   // ventas por línea
		foreach ( $ds_by as $code => $ds ) {
			$cur  = Support::currency( $corporate, $code, $ds );
			$file = $ds['meta']['file'] ?? null;
			$rates[ $code ] = [ 'currency' => $cur, 'opening' => Fx::opening( $corporate, $version, $cur ) ];
			for ( $m = 1; $m <= 12; $m++ ) {
				$rates[ $code ]['avg'][ $m ]   = Fx::rate( $corporate, $version, $cur, $m, 'avg' );
				$rates[ $code ]['close'][ $m ] = Fx::rate( $corporate, $version, $cur, $m, 'close' );
			}
			foreach ( [ 'pl' => true, 'bs' => false ] as $part => $is_pl ) {
				foreach ( (array) ( $ds[ $part ] ?? [] ) as $r ) {
					$acc = (string) ( $r['concept'] ?? '' );
					if ( ! isset( $catalog[ $acc ] ) || ! is_numeric( $r['value'] ?? null ) ) {
						continue;
					}
					$m    = (int) ( $r['month'] ?? 0 );
					$v    = (float) $r['value'];
					$v    = Support::sign( $catalog[ $acc ] ) < 0 ? abs( $v ) : $v; // 1502 en positivo (§3)
					$rate = $is_pl ? $rates[ $code ]['avg'][ max( 1, $m ) ] : ( 0 === $m ? $rates[ $code ]['opening'] : $rates[ $code ]['close'][ $m ] );
					$u    = $v / $rate;
					$usd[ $acc ][ $code ][ $m ]   = ( $usd[ $acc ][ $code ][ $m ] ?? 0.0 ) + $u;
					$local[ $acc ][ $code ][ $m ] = ( $local[ $acc ][ $code ][ $m ] ?? 0.0 ) + $v;
					$sources[ $acc ][] = [
						'company' => $code, 'version' => $version, 'file' => $file,
						'sheet' => $r['src']['sheet'] ?? null, 'row' => $r['src']['row'] ?? null, 'col' => $r['src']['col'] ?? null,
						'month' => $m, 'local' => $v, 'rate' => $rate, 'usd' => $u,
					];
				}
			}
			$stats[ $code ] = self::sales_stats( $ds, $roles, $lines, $rates[ $code ] );
		}

		// Eliminaciones.
		$ic    = $ic ?? Intercompany::reconcile( $datasets, $corporate, $version );
		$elim  = [];   // [acc][m]
		$items = [];
		$unrec = [ 'flows' => 0.0, 'balances' => 0.0 ];
		$add   = function ( string $type, int $m, array $acc_amounts, array $extra ) use ( &$elim, &$items ) {
			$accs = [];
			foreach ( $acc_amounts as $acc => $amt ) {
				if ( null === $acc || '' === (string) $acc ) {
					continue;
				}
				$elim[ $acc ][ $m ] = ( $elim[ $acc ][ $m ] ?? 0.0 ) + $amt;
				$accs[ $acc ]       = ( $accs[ $acc ] ?? 0.0 ) + $amt;
			}
			$items[] = [ 'type' => $type, 'month' => $m, 'accounts' => $accs ] + $extra;
		};
		$first_cogs = $roles['cogs'] ? reset( $roles['cogs'] ) : null;
		$first_sic  = $roles['sales_ic'] ? reset( $roles['sales_ic'] ) : null;
		foreach ( $ic['pairs'] as $p ) {
			if ( 'cuadra' !== $p['status'] ) {
				$unrec['flows'] += abs( (float) $p['diff_usd'] );
			}
			if ( null === $p['amount_from_usd'] || 'loan' === $p['type'] ) {
				continue; // sin lado vendedor no se elimina; los préstamos se eliminan por saldo
			}
			$u   = (float) $p['amount_from_usd'];
			$map = match ( $p['type'] ) {
				'product_sale'   => [ $roles['sales_ic'][ $p['line'] ?? '' ] ?? $first_sic => -$u, $roles['cogs'][ $p['line'] ?? '' ] ?? $first_cogs => -$u ],
				'management_fee' => [ $roles['mgmt_income'] => -$u, $roles['mgmt_expense'] => -$u ],
				'loan_interest'  => [ $roles['int_income'] => -$u, $roles['int_expense'] => -$u ],
				default          => [],
			};
			$add( $p['type'], (int) $p['month'], $map, [ 'from' => $p['from'], 'to' => $p['to'], 'line' => $p['line'], 'usd' => $u, 'status' => $p['status'], 'diff_usd' => 'cuadra' === $p['status'] ? 0.0 : $p['diff_usd'], 'src' => [ 'from' => $p['src_from'], 'to' => $p['src_to'] ] ] );
		}
		// Saldos: se elimina lo que reporta el acreedor; la diferencia con el deudor queda sin conciliar.
		foreach ( [ [ 'ic_receivable', 'ic_payable', 'saldo' ], [ 'loan_receivable', 'loan_payable', 'prestamo' ] ] as [ $ra, $pa, $type ] ) {
			$racc = $roles[ $ra ];
			$pacc = $roles[ $pa ];
			if ( null === $racc || null === $pacc ) {
				continue;
			}
			for ( $m = 0; $m <= 12; $m++ ) {
				$r = 0.0;
				$q = 0.0;
				foreach ( $codes as $c ) {
					$r += $usd[ $racc ][ $c ][ $m ] ?? 0.0;
					$q += $usd[ $pacc ][ $c ][ $m ] ?? 0.0;
				}
				if ( 0.0 === $r ) {
					continue;
				}
				$add( $type, $m, [ $racc => -$r, $pacc => -$r ], [ 'usd' => $r, 'payable_usd' => $q, 'diff_usd' => $r - $q ] );
				if ( 12 === $m ) {
					$unrec['balances'] += abs( $r - $q );
				}
			}
		}
		// Margen no realizado del inventario comprado al grupo.
		$margins = [];
		$up      = array_fill( 0, 13, 0.0 );
		foreach ( $ds_by as $buyer => $ds ) {
			foreach ( (array) ( $ds['ic_inventory'] ?? [] ) as $row ) {
				$sup  = Intercompany::company_code( (string) ( $row['supplier'] ?? '' ), $corporate, $codes );
				$line = Intercompany::line( (string) ( $row['line'] ?? '' ), $lines );
				$m    = (int) ( $row['month'] ?? 0 );
				if ( null === $sup || ! isset( $stats[ $sup ][ $line ] ) ) {
					continue;
				}
				$margins[ $sup ][ $line ] ??= self::gross_margin( $stats[ $sup ][ $line ] );
				$mg = $margins[ $sup ][ $line ];
				if ( null === $mg ) {
					continue;
				}
				$rate  = 0 === $m ? $rates[ $buyer ]['opening'] : $rates[ $buyer ]['close'][ $m ];
				$inv   = (float) ( $row['amount'] ?? 0 ) / $rate;
				$up[ $m ] += $inv * $mg;
				$items[] = [ 'type' => 'margen_inventario', 'month' => $m, 'from' => $sup, 'to' => $buyer, 'line' => $line, 'inventory_usd' => $inv, 'margin' => $mg, 'usd' => $inv * $mg, 'accounts' => [], 'src' => [ 'to' => $row['src'] ?? null ] ];
			}
		}
		if ( $roles['ic_inventory'] && array_sum( array_map( 'abs', $up ) ) > 0 ) {
			for ( $m = 0; $m <= 12; $m++ ) {
				$elim[ $roles['ic_inventory'] ][ $m ] = ( $elim[ $roles['ic_inventory'] ][ $m ] ?? 0.0 ) - $up[ $m ];
				if ( $roles['equity'] ) {
					$elim[ $roles['equity'] ][ $m ] = ( $elim[ $roles['equity'] ][ $m ] ?? 0.0 ) - $up[ $m ];
				}
				if ( $m > 0 && $roles['margin_elim'] ) {
					$elim[ $roles['margin_elim'] ][ $m ] = ( $elim[ $roles['margin_elim'] ][ $m ] ?? 0.0 ) + $up[ $m ] - $up[ $m - 1 ];
				}
			}
		}

		// Métricas por país (antes de eliminaciones) y minoritarios.
		$by_company = [];
		$minority   = [ 'income' => 0.0, 'equity' => 0.0, 'by_company' => [] ];
		$cta        = [];
		foreach ( $codes as $c ) {
			$m_usd   = self::metrics( $catalog, fn( $acc, $m ) => $usd[ $acc ][ $c ][ $m ] ?? 0.0 );
			$m_loc   = self::metrics( $catalog, fn( $acc, $m ) => $local[ $acc ][ $c ][ $m ] ?? 0.0 );
			$eq      = $roles['equity'];
			$eq_open = $eq ? ( $usd[ $eq ][ $c ][0] ?? 0.0 ) : 0.0;
			$eq_cls  = $eq ? ( $usd[ $eq ][ $c ][12] ?? 0.0 ) : 0.0;
			$share   = (float) ( $corporate['companies'][ $c ]['minority'] ?? ( 1 - (float) ( $corporate['companies'][ $c ]['ownership'] ?? 1 ) ) );
			$by_company[ $c ] = $m_usd + [ 'local' => $m_loc, 'currency' => $rates[ $c ]['currency'], 'equity' => $eq_cls, 'equity_open' => $eq_open, 'minority_share' => $share ];
			$cta[ $c ]        = [
				'equity_close_usd' => $eq_cls,
				'equity_open_usd'  => $eq_open,
				'net_income_usd'   => $m_usd['net_income'],
				'cta'              => $eq_cls - ( $eq_open + $m_usd['net_income'] ),
			];
			if ( $share > 0 ) {
				$mi = [ 'share' => $share, 'income' => $share * $m_usd['net_income'], 'equity' => $share * $eq_cls, 'months' => [] ];
				for ( $m = 0; $m <= 12; $m++ ) {
					$e = $share * ( $eq ? ( $usd[ $eq ][ $c ][ $m ] ?? 0.0 ) : 0.0 );
					$mi['months'][ $m ] = $e;
					if ( $roles['minority'] && $eq ) {
						$elim[ $roles['minority'] ][ $m ] = ( $elim[ $roles['minority'] ][ $m ] ?? 0.0 ) + $e;
						$elim[ $eq ][ $m ]                = ( $elim[ $eq ][ $m ] ?? 0.0 ) - $e;
					}
				}
				$minority['by_company'][ $c ] = $mi;
				$minority['income']          += $mi['income'];
				$minority['equity']          += $mi['equity'];
				$items[] = [ 'type' => 'minoritario', 'month' => 12, 'from' => $c, 'to' => null, 'usd' => $mi['equity'], 'accounts' => array_filter( [ $roles['minority'] => $mi['equity'], $eq => -$mi['equity'] ] ) ];
			}
		}

		// Líneas consolidadas.
		$out_lines = [];
		$totals_m  = [];
		foreach ( $catalog as $acc => $a ) {
			$acc    = (string) $acc;
			$is_pl  = Support::is_pl( $a );
			$months = $is_pl ? range( 1, 12 ) : range( 0, 12 );
			$bcm    = [];
			$bcl    = [];
			$tot_m  = [];
			$el_m   = [];
			foreach ( $months as $m ) {
				$t = 0.0;
				foreach ( $codes as $c ) {
					$bcm[ $c ][ $m ] = $usd[ $acc ][ $c ][ $m ] ?? 0.0;
					$bcl[ $c ][ $m ] = $local[ $acc ][ $c ][ $m ] ?? 0.0;
					$t              += $bcm[ $c ][ $m ];
				}
				$el_m[ $m ]  = $elim[ $acc ][ $m ] ?? 0.0;
				$tot_m[ $m ] = $t + $el_m[ $m ];
			}
			$pick = fn( array $series ) => $is_pl ? array_sum( $series ) : ( $series[12] ?? 0.0 );
			$line = [
				'account'          => $acc,
				'name'             => (string) ( $a['name'] ?? $acc ),
				'statement'        => $is_pl ? 'PL' : 'BS',
				'type'             => (string) ( $a['type'] ?? '' ),
				'subtype'          => (string) ( $a['subtype'] ?? '' ),
				'sign'             => Support::sign( $a ),
				'by_company'       => array_map( $pick, $bcm ),
				'by_company_local' => array_map( $pick, $bcl ),
				'by_company_months' => $bcm,
				'local_months'     => $bcl,
				'eliminations'     => $pick( $el_m ),
				'elim_months'      => $el_m,
				'total'            => $pick( $tot_m ),
				'months'           => $tot_m,
				'sources'          => $sources[ $acc ] ?? [],
			];
			if ( ! $is_pl ) {
				$line['close']               = $tot_m[12] ?? 0.0;
				$line['open']                = $tot_m[0] ?? 0.0;
				$line['by_company_open']     = array_map( fn( $s ) => $s[0] ?? 0.0, $bcm );
				$line['by_company_local_open'] = array_map( fn( $s ) => $s[0] ?? 0.0, $bcl );
			}
			$out_lines[] = $line;
			$totals_m[ $acc ] = $tot_m;
		}
		$totals = self::metrics( $catalog, fn( $acc, $m ) => $totals_m[ $acc ][ $m ] ?? 0.0 );
		$totals['net_income_group'] = $totals['net_income'] - $minority['income'];
		$totals['minority_income']  = $minority['income'];

		return [
			'version'         => $version,
			'companies'       => $codes,
			'lines'           => $out_lines,
			'eliminations'    => $items,
			'ic_unreconciled' => $unrec['flows'] + $unrec['balances'],
			'ic_unreconciled_detail' => $unrec,
			'minority'        => $minority,
			'cta'             => $cta,
			'totals'          => $totals,
			'by_company'      => $by_company,
			'margins'         => $margins,
			'sales_stats'     => $stats,
			'rates'           => $rates,
			'ic'              => $ic,
		];
	}

	/**
	 * Ingresos operativos, EBIT y utilidad neta a partir de un lector de valores ($get(acc, mes)).
	 * EBIT = ingresos operativos − costos − gastos operativos; utilidad = EBIT + ingresos no operativos − gastos no
	 * operativos (incluye impuesto).
	 */
	public static function metrics( array $catalog, callable $get ): array {
		$out = [ 'revenue' => 0.0, 'ebit' => 0.0, 'net_income' => 0.0, 'months' => [] ];
		foreach ( $catalog as $acc => $a ) {
			if ( ! Support::is_pl( $a ) ) {
				continue;
			}
			$e = Support::pl_effect( $a );
			for ( $m = 1; $m <= 12; $m++ ) {
				$v = (float) $get( (string) $acc, $m );
				if ( 0.0 === $v ) {
					continue;
				}
				$out['months'][ $m ] ??= [ 'revenue' => 0.0, 'ebit' => 0.0, 'net_income' => 0.0 ];
				if ( 'ingreso' === ( $a['type'] ?? '' ) && $e['ebit'] ) {
					$out['revenue']                   += $v;
					$out['months'][ $m ]['revenue']   += $v;
				}
				$out['ebit']                       += $e['ebit'] * $v;
				$out['net_income']                 += $e['net'] * $v;
				$out['months'][ $m ]['ebit']       += $e['ebit'] * $v;
				$out['months'][ $m ]['net_income'] += $e['net'] * $v;
			}
		}
		ksort( $out['months'] );
		return $out;
	}

	/**
	 * Estadística de ventas por línea y mes: volumen, precio, ventas netas (local), ventas por cuentas (local y USD),
	 * costo (local). La usan el margen no realizado y Variance.
	 */
	private static function sales_stats( array $ds, array $roles, array $lines, array $rates ): array {
		$pl  = Support::grid( (array) ( $ds['pl'] ?? [] ) );
		$out = [];
		foreach ( $lines as $line ) {
			for ( $m = 1; $m <= 12; $m++ ) {
				$third = Support::val( $pl, (string) ( $roles['sales_third'][ $line ] ?? '' ), $m );
				$icv   = Support::val( $pl, (string) ( $roles['sales_ic'][ $line ] ?? '' ), $m );
				$net   = $pl[ 'net_sales:' . $line ][ $m ]['value'] ?? null;
				$out[ $line ][ $m ] = [
					'volume'      => (float) ( $pl[ 'volume:' . $line ][ $m ]['value'] ?? 0 ),
					'price'       => (float) ( $pl[ 'price:' . $line ][ $m ]['value'] ?? 0 ),
					'net_local'   => null === $net ? $third + $icv : (float) $net,
					'sales_local' => $third + $icv,
					'third_local' => $third,
					'ic_local'    => $icv,
					'cogs_local'  => Support::val( $pl, (string) ( $roles['cogs'][ $line ] ?? '' ), $m ),
					'rate'        => $rates['avg'][ $m ],
					'sales_usd'   => ( $third + $icv ) / $rates['avg'][ $m ],
				];
			}
		}
		return $out;
	}

	/** Margen bruto anual de una línea: (ventas − costo) / ventas. */
	private static function gross_margin( array $months ): ?float {
		$s = array_sum( array_column( $months, 'sales_local' ) );
		$c = array_sum( array_column( $months, 'cogs_local' ) );
		return $s > 0 ? ( $s - $c ) / $s : null;
	}
}
