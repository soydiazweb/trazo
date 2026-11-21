<?php
/**
 * Conciliación intercompañía entre países por mes y tipo (CONTRACT §8).
 *
 * Pares: venta (`sale` de A con `purchase` de B), cuota y intereses (`charge` con `payment`), préstamo (`granted`
 * con `received`), comparados en moneda pactada (0,5 %). Además: (a) ventas/ingresos intercompañía de resultados
 * contra la hoja intercompania convertida a moneda local; (b) saldos por cobrar/pagar y préstamos entre países a
 * cierre de cada mes en USD (1 %); (c) diferencias de tiempo («en tránsito» o compensadas al mes siguiente).
 *
 * PHP puro: sin funciones de WordPress.
 */

namespace Consolida\Engine;

final class Intercompany {

	public const TOLERANCE     = 0.005; // 0,5 %
	public const TOL_BALANCE   = 0.01;  // 1 %
	public const TOL_PL_TIE    = 0.005; // 0,5 %
	public const GROUP_COMPANY = 'GRUPO';

	/** Sentido que marca a la parte que vende / cobra / presta, por tipo. */
	private const FROM_DIRECTION = [
		'product_sale'   => [ 'sale', 'purchase' ],
		'management_fee' => [ 'charge', 'payment' ],
		'loan_interest'  => [ 'charge', 'payment' ],
		'loan'           => [ 'granted', 'received' ],
	];

	/** Alias de sentidos (por si el lector deja el texto original). */
	private const DIRECTION_ALIASES = [
		'venta' => 'sale', 'compra' => 'purchase', 'cobro' => 'charge', 'cargo' => 'charge', 'pago' => 'payment',
		'expense' => 'payment', 'gasto' => 'payment', 'otorgado' => 'granted', 'recibido' => 'received',
	];

	/** Alias de línea en inglés o por código de producto. */
	private const LINE_ALIASES = [ 'beverages' => 'bebidas', 'confectionery' => 'confiteria', 'snk' => 'snacks', 'beb' => 'bebidas', 'con' => 'confiteria' ];

	/**
	 * @param array  $datasets  code => Dataset §6 de la versión.
	 * @param array  $corporate Corporate §4.
	 * @param string $version   clave de versión.
	 * @param array  $known_issues Issues de lectura y validación (opcional): los meses con FMT_DATE o FMT_SCALE_JUMP de
	 *                             un país no se vuelven a reportar en los cruces de resultados y saldos (se reporta la
	 *                             causa, no la consecuencia).
	 * @return array{pairs:array,issues:array,pl_tie:array,balances:array}
	 */
	public static function reconcile( array $datasets, array $corporate, string $version, array $known_issues = [] ): array {
		$codes = self::codes( $datasets );
		$lines = self::normalized_lines( $datasets, $corporate, $codes );

		// 1) Pares por tipo, mes, de, a y línea.
		$buckets = [];
		foreach ( $lines as $i => $l ) {
			$key = implode( '|', [ $l['type'], $l['month'], $l['from'], $l['to'], $l['line'] ?? '', $l['timing'] ? 't' . $i : '' ] );
			$buckets[ $key ] ??= [ 'type' => $l['type'], 'month' => $l['month'], 'from' => $l['from'], 'to' => $l['to'], 'line' => $l['line'], 'timing' => $l['timing'], 'sides' => [ 'from' => [], 'to' => [] ] ];
			$buckets[ $key ]['sides'][ $l['side'] ][] = $l;
		}
		$pairs = [];
		foreach ( $buckets as $b ) {
			$pairs[] = self::pair( $b, $corporate, $version );
		}
		self::mark_timing( $pairs );
		usort( $pairs, fn( $a, $b ) => [ $a['month'], $a['type'], $a['from'], $a['to'], $a['line'] ?? '' ] <=> [ $b['month'], $b['type'], $b['from'], $b['to'], $b['line'] ?? '' ] );

		$issues  = [];
		$missing = [];
		foreach ( $pairs as $p ) {
			if ( 'falta_lado' === $p['status'] ) {
				$missing[ implode( '|', [ $p['type'], $p['from'], $p['to'], $p['line'] ?? '', null === $p['amount_to'] ? 'to' : 'from' ] ) ][] = $p;
			} elseif ( 'cuadra' !== $p['status'] ) {
				$issues = array_merge( $issues, self::pair_issues( $p, $version ) );
			}
		}
		// Un mismo faltante repetido varios meses es un solo hallazgo por empresa.
		foreach ( $missing as $group ) {
			$issues = array_merge( $issues, 1 === count( $group ) ? self::pair_issues( $group[0], $version ) : self::missing_issues( $group, $version ) );
		}

		// 2) Resultados vs hoja intercompania; 3) saldos.
		$flag     = [];
		foreach ( $known_issues as $i ) {
			if ( in_array( $i['code'] ?? '', [ 'FMT_DATE', 'FMT_SCALE_JUMP' ], true ) && isset( $i['month'] ) && null !== $i['month'] ) {
				$flag[ (string) ( $i['company'] ?? '' ) ][ (string) ( $i['sheet'] ?? '' ) ][ (int) $i['month'] ] = true;
			}
		}
		$tie      = self::pl_tie( $datasets, $corporate, $version, $lines, $flag );
		$balances = self::balances( $datasets, $corporate, $version, $lines, $flag, $pairs );
		$issues   = array_merge( $issues, $tie['issues'], $balances['issues'] );

		return [ 'pairs' => $pairs, 'issues' => $issues, 'pl_tie' => $tie['rows'], 'balances' => $balances['rows'] ];
	}

	/**
	 * Líneas de la hoja intercompania de todos los países con lado (from/to) normalizado.
	 */
	public static function normalized_lines( array $datasets, array $corporate, ?array $codes = null ): array {
		$codes   = $codes ?? self::codes( $datasets );
		$known   = array_unique( array_merge( $codes, array_map( 'strval', array_keys( (array) ( $corporate['companies'] ?? [] ) ) ) ) );
		$plines  = Support::lines( $corporate );
		$out     = [];
		foreach ( $datasets as $key => $ds ) {
			$self = (string) ( $ds['meta']['company'] ?? $key );
			foreach ( (array) ( $ds['ic'] ?? [] ) as $l ) {
				$type = (string) ( $l['type'] ?? '' );
				$dir  = Support::plain( (string) ( $l['direction'] ?? '' ) );
				$dir  = self::DIRECTION_ALIASES[ $dir ] ?? $dir;
				$cp   = self::company_code( (string) ( $l['counterparty'] ?? '' ), $corporate, $known );
				if ( ! isset( self::FROM_DIRECTION[ $type ] ) || null === $cp || $cp === $self ) {
					continue;
				}
				[ $fd, $td ] = self::FROM_DIRECTION[ $type ];
				if ( $dir === $fd ) {
					$side = 'from';
					$from = $self;
					$to   = $cp;
				} elseif ( $dir === $td ) {
					$side = 'to';
					$from = $cp;
					$to   = $self;
				} else {
					continue;
				}
				$note  = (string) ( $l['note'] ?? '' );
				$out[] = [
					'company'  => $self,
					'type'     => $type,
					'direction' => $dir,
					'side'     => $side,
					'from'     => $from,
					'to'       => $to,
					'month'    => (int) ( $l['month'] ?? 0 ),
					'line'     => 'product_sale' === $type ? self::line( (string) ( $l['line'] ?? '' ), $plines ) : null,
					'amount'   => (float) ( $l['amount'] ?? 0 ),
					'currency' => strtoupper( (string) ( $l['currency'] ?? 'USD' ) ) ?: 'USD',
					'note'     => '' === $note ? null : $note,
					'timing'   => (bool) preg_match( '/tr[aá]nsito|transit/iu', $note ),
					'src'      => $l['src'] ?? null,
				];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ pares */

	private static function pair( array $b, array $corp, string $version ): array {
		$sum = [];
		foreach ( [ 'from', 'to' ] as $side ) {
			$ls = $b['sides'][ $side ];
			if ( ! $ls ) {
				$sum[ $side ] = null;
				continue;
			}
			$curs = array_unique( array_column( $ls, 'currency' ) );
			$usd  = 0.0;
			$amt  = 0.0;
			foreach ( $ls as $l ) {
				$usd += self::usd( $l, $corp, $version );
				$amt += $l['amount'];
			}
			$sum[ $side ] = [
				'currency' => 1 === count( $curs ) ? $curs[0] : 'USD',
				'amount'   => 1 === count( $curs ) ? $amt : $usd,
				'usd'      => $usd,
				'src'      => $ls[0]['src'],
				'note'     => implode( ' · ', array_filter( array_column( $ls, 'note' ) ) ) ?: null,
				'n'        => count( $ls ),
			];
		}
		$f = $sum['from'];
		$t = $sum['to'];
		$same = $f && $t && $f['currency'] === $t['currency'];
		$cur  = $same ? $f['currency'] : ( $f['currency'] ?? $t['currency'] ?? 'USD' );
		if ( $f && $t ) {
			$diff     = $same ? $f['amount'] - $t['amount'] : $f['usd'] - $t['usd'];
			$base     = $same ? max( abs( $f['amount'] ), abs( $t['amount'] ) ) : max( abs( $f['usd'] ), abs( $t['usd'] ) );
			$status   = abs( $diff ) <= self::TOLERANCE * $base ? 'cuadra' : 'no_cuadra';
		} else {
			$diff   = $f ? $f['amount'] : -$t['amount'];
			$status = 'falta_lado';
		}
		if ( 'cuadra' !== $status && $b['timing'] ) {
			$status = 'tiempo';
		}
		return [
			'type'            => $b['type'],
			'month'           => $b['month'],
			'from'            => $b['from'],
			'to'              => $b['to'],
			'line'            => $b['line'],
			'currency'        => $same || ! ( $f && $t ) ? $cur : 'USD',
			'currency_from'   => $f['currency'] ?? null,
			'currency_to'     => $t['currency'] ?? null,
			'amount_from'     => $f['amount'] ?? null,
			'amount_to'       => $t['amount'] ?? null,
			'amount_from_usd' => $f['usd'] ?? null,
			'amount_to_usd'   => $t['usd'] ?? null,
			'status'          => $status,
			'diff'            => $diff,
			'diff_usd'        => (float) ( $f['usd'] ?? 0.0 ) - (float) ( $t['usd'] ?? 0.0 ),
			'note'            => trim( implode( ' · ', array_filter( [ $f['note'] ?? null, $t['note'] ?? null ] ) ) ) ?: null,
			'src_from'        => $f['src'] ?? null,
			'src_to'          => $t['src'] ?? null,
		];
	}

	/**
	 * Diferencias de tiempo: si la diferencia de un mes se compensa en el mes siguiente (mismo tipo, partes y línea),
	 * ambos pares quedan como 'tiempo'.
	 */
	private static function mark_timing( array &$pairs ): void {
		$by = [];
		foreach ( $pairs as $i => $p ) {
			if ( 'cuadra' !== $p['status'] ) {
				$by[ implode( '|', [ $p['type'], $p['from'], $p['to'], $p['line'] ?? '' ] ) ][ $p['month'] ][] = $i;
			}
		}
		foreach ( $by as $months ) {
			foreach ( $months as $m => $idxs ) {
				foreach ( $idxs as $i ) {
					foreach ( $months[ $m + 1 ] ?? [] as $j ) {
						$a = $pairs[ $i ]['diff_usd'];
						$b = $pairs[ $j ]['diff_usd'];
						if ( 'tiempo' !== $pairs[ $j ]['status'] && abs( $a + $b ) <= 0.01 * max( abs( $a ), abs( $b ) ) ) {
							$pairs[ $i ]['status'] = 'tiempo';
							$pairs[ $j ]['status'] = 'tiempo';
						}
					}
				}
			}
		}
	}

	/** Issues de un par (uno por empresa implicada). */
	private static function pair_issues( array $p, string $version ): array {
		$what = Validator::ic_type_name( $p['type'] ) . ( $p['line'] ? ' de ' . $p['line'] : '' );
		$mon  = Support::month_name( $p['month'] );
		$fa   = null === $p['amount_from'] ? null : Support::fmt( $p['amount_from'], 2 ) . ' ' . $p['currency_from'];
		$ta   = null === $p['amount_to'] ? null : Support::fmt( $p['amount_to'], 2 ) . ' ' . $p['currency_to'];
		switch ( $p['status'] ) {
			case 'no_cuadra':
				[ $code, $sev ] = [ 'IC_MISMATCH', Support::CRITICAL ];
				$msg = sprintf( 'En %s, %s reporta %s a %s por %s y %s reporta %s (diferencia %s USD).', $mon, $p['from'], $what, $p['to'], $fa, $p['to'], $ta, Support::fmt( $p['diff_usd'], 2 ) );
				if ( $p['currency_from'] !== $p['currency_to'] ) {
					$msg .= sprintf( ' Además, uno lo pacta en %s y el otro en %s.', $p['currency_from'], $p['currency_to'] );
				}
				break;
			case 'falta_lado':
				[ $code, $sev ] = [ 'IC_MISSING', Support::CRITICAL ];
				$msg = null === $p['amount_to']
					? sprintf( 'En %s, %s reporta %s a %s por %s, pero %s no lo registra.', $mon, $p['from'], $what, $p['to'], $fa, $p['to'] )
					: sprintf( 'En %s, %s registra %s de %s por %s, pero %s no lo reporta.', $mon, $p['to'], $what, $p['from'], $ta, $p['from'] );
				break;
			default:
				[ $code, $sev ] = [ 'IC_TIMING', Support::WARNING ];
				$msg = sprintf( 'En %s, %s entre %s y %s tiene una diferencia de tiempo (%s USD)%s.', $mon, $what, $p['from'], $p['to'], Support::fmt( $p['diff_usd'], 2 ), $p['note'] ? ': «' . $p['note'] . '»' : ', compensada en el mes siguiente' );
		}
		$out = [];
		foreach ( [ 'from', 'to' ] as $side ) {
			$out[] = Support::issue( $code, $sev, 'intercompania', Support::at( $p[ 'src_' . $side ] ) + [
				'company'    => $p[ $side ],
				'version'    => $version,
				'month'      => $p['month'],
				'concept'    => 'ic:' . $p['type'],
				'actual'     => 'from' === $side ? $p['amount_from'] : $p['amount_to'],
				'expected'   => 'from' === $side ? $p['amount_to'] : $p['amount_from'],
				'impact_usd' => abs( $p['diff_usd'] ),
			], $msg );
		}
		return $out;
	}

	/** Faltante repetido en varios meses: un issue por empresa con los meses y el total. */
	private static function missing_issues( array $group, string $version ): array {
		$p      = $group[0];
		$what   = Validator::ic_type_name( $p['type'] ) . ( $p['line'] ? ' de ' . $p['line'] : '' );
		$months = array_map( fn( $x ) => (int) $x['month'], $group );
		$usd    = array_sum( array_map( fn( $x ) => abs( (float) $x['diff_usd'] ), $group ) );
		$amt    = array_sum( array_map( fn( $x ) => (float) ( $x['amount_from'] ?? $x['amount_to'] ), $group ) );
		$cur    = $p['currency_from'] ?? $p['currency_to'];
		$msg    = null === $p['amount_to']
			? sprintf( '%s reporta %s a %s en %s (%d meses, %s %s en total), pero %s no lo registra.', $p['from'], $what, $p['to'], Validator::months_text( $months ), count( $group ), Support::fmt( $amt, 2 ), $cur, $p['to'] )
			: sprintf( '%s registra %s de %s en %s (%d meses, %s %s en total), pero %s no lo reporta.', $p['to'], $what, $p['from'], Validator::months_text( $months ), count( $group ), Support::fmt( $amt, 2 ), $cur, $p['from'] );
		$out = [];
		foreach ( [ 'from', 'to' ] as $side ) {
			$out[] = Support::issue( 'IC_MISSING', Support::CRITICAL, 'intercompania', Support::at( $p[ 'src_' . $side ] ) + [
				'company' => $p[ $side ], 'version' => $version, 'month' => $p['month'], 'concept' => 'ic:' . $p['type'],
				'actual' => 'from' === $side ? $p['amount_from'] : $p['amount_to'], 'expected' => 'from' === $side ? $p['amount_to'] : $p['amount_from'], 'impact_usd' => $usd,
			], $msg );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ (a) resultados vs hoja intercompania */

	/**
	 * Ingresos intercompañía de resultados (ventas IC por línea, cuota e intereses ganados) del mes = suma de la hoja
	 * intercompania (lado que vende/cobra) convertida a moneda local con la tasa promedio del mes.
	 */
	private static function pl_tie( array $datasets, array $corp, string $version, array $lines, array $flag = [] ): array {
		$roles = Support::roles( $corp );
		$rows  = [];
		$out   = [];
		$exp   = [];
		foreach ( $lines as $l ) {
			if ( 'from' !== $l['side'] || 'loan' === $l['type'] ) {
				continue;
			}
			$acc = match ( $l['type'] ) {
				'product_sale'   => $roles['sales_ic'][ $l['line'] ?? '' ] ?? null,
				'management_fee' => $roles['mgmt_income'],
				'loan_interest'  => $roles['int_income'],
				default          => null,
			};
			if ( null === $acc ) {
				continue;
			}
			$exp[ $l['company'] ][ $acc ][ $l['month'] ][] = $l;
		}
		$accounts = array_filter( array_merge( array_values( $roles['sales_ic'] ), [ $roles['mgmt_income'], $roles['int_income'] ] ) );
		foreach ( $datasets as $key => $ds ) {
			$code  = (string) ( $ds['meta']['company'] ?? $key );
			$cur   = Support::currency( $corp, $code, $ds );
			$scale = max( 1.0, (float) ( $ds['meta']['scale_applied'] ?? 1 ) );
			$grid  = Support::grid( (array) ( $ds['pl'] ?? [] ) );
			foreach ( $accounts as $acc ) {
				$bad = [];
				for ( $m = 1; $m <= 12; $m++ ) {
					if ( isset( $flag[ $code ]['resultados'][ $m ] ) || isset( $flag[ $code ]['intercompania'][ $m ] ) ) {
						continue;
					}
					$pl   = Support::val( $grid, $acc, $m );
					$from = $exp[ $code ][ $acc ][ $m ] ?? [];
					$ic   = 0.0;
					foreach ( $from as $l ) {
						$ic += Fx::from_usd( self::usd( $l, $corp, $version ), $corp, $version, $cur, $m );
					}
					if ( 0.0 === $pl && ! $from ) {
						continue;
					}
					$ok     = abs( $pl - $ic ) <= max( self::TOL_PL_TIE * max( abs( $pl ), abs( $ic ) ), $scale );
					$rows[] = [ 'company' => $code, 'account' => $acc, 'month' => $m, 'pl' => $pl, 'ic' => $ic, 'ok' => $ok ];
					if ( ! $ok ) {
						$bad[ $m ] = [ $pl, $ic, $grid[ $acc ][ $m ]['src'] ?? ( $from[0]['src'] ?? null ) ];
					}
				}
				if ( ! $bad ) {
					continue;
				}
				// Un issue por empresa y cuenta con los meses afectados (el primero como ubicación).
				$m0              = (int) array_key_first( $bad );
				[ $pl, $ic, $src ] = $bad[ $m0 ];
				$rate            = Fx::try_rate( $corp, $version, $cur, $m0, 'avg' ) ?: 1.0;
				$impact          = 0.0;
				foreach ( $bad as $m => [ $a, $b ] ) {
					$impact += abs( $a - $b ) / ( Fx::try_rate( $corp, $version, $cur, $m, 'avg' ) ?: 1.0 );
				}
				$name  = $corp['catalog'][ $acc ]['name'] ?? $acc;
				$out[] = Support::issue( 'IC_PL_TIE', Support::CRITICAL, 'intercompania', Support::at( $src ) + [
					'company' => $code, 'version' => $version, 'month' => $m0, 'concept' => $acc, 'actual' => $pl, 'expected' => $ic, 'impact_usd' => $impact,
				], sprintf( 'La cuenta %s (%s) no cuadra con la hoja intercompania en %d %s; en %s: resultados %s y hoja intercompania %s (diferencia %s USD).',
					$acc, $name, count( $bad ), 1 === count( $bad ) ? 'mes' : 'meses', Support::month_name( $m0 ),
					Support::money( $pl, $cur ), Support::money( $ic, $cur ), Support::fmt( ( $pl - $ic ) / $rate, 0 ) ) );
			}
		}
		return [ 'rows' => $rows, 'issues' => $out ];
	}

	/* ------------------------------------------------------------------ (b) saldos */

	/**
	 * Saldos a cierre de cada mes en USD: Σ cuentas por cobrar IC = Σ cuentas por pagar IC del grupo, y préstamos
	 * por cobrar = por pagar (por par prestamista → deudor cuando la hoja intercompania lo identifica).
	 * CONTRATO: el balance no separa saldos por contraparte; las cxc/cxp se comparan a nivel grupo (empresa «GRUPO»).
	 */
	private static function balances( array $datasets, array $corp, string $version, array $lines, array $flag = [], array $pairs = [] ): array {
		$roles = Support::roles( $corp );
		$usd   = [];
		$srcs  = [];
		foreach ( $datasets as $key => $ds ) {
			$code = (string) ( $ds['meta']['company'] ?? $key );
			$cur  = Support::currency( $corp, $code, $ds );
			$grid = Support::grid( (array) ( $ds['bs'] ?? [] ) );
			foreach ( [ 'ic_receivable', 'ic_payable', 'loan_receivable', 'loan_payable' ] as $role ) {
				$acc = $roles[ $role ];
				if ( null === $acc ) {
					continue;
				}
				foreach ( $grid[ $acc ] ?? [] as $m => $cell ) {
					$rate = Fx::try_rate( $corp, $version, $cur, (int) $m, 'close' );
					if ( $rate ) {
						$usd[ $role ][ $code ][ $m ] = (float) ( $cell['value'] ?? 0 ) / $rate;
						$srcs[ $role ][ $code ][ $m ] = $cell['src'];
					}
				}
			}
		}
		$rows   = [];
		$issues = [];
		// cxc vs cxp del grupo.
		$checks = [ [ 'ic_receivable', 'ic_payable', 'saldos por cobrar y por pagar intercompañía', array_keys( $usd['ic_receivable'] ?? [] ), array_keys( $usd['ic_payable'] ?? [] ), self::GROUP_COMPANY ] ];
		// Préstamos por par prestamista → deudor.
		$loans = [];
		foreach ( $lines as $l ) {
			if ( in_array( $l['type'], [ 'loan', 'loan_interest' ], true ) ) {
				$loans[ $l['from'] ][ $l['to'] ] = true;
			}
		}
		$lenders_of = [];
		foreach ( $loans as $lender => $debtors ) {
			foreach ( array_keys( $debtors ) as $d ) {
				$lenders_of[ $d ][ $lender ] = true;
			}
		}
		$paired = [];
		foreach ( $loans as $lender => $debtors ) {
			if ( 1 === count( $debtors ) ) {
				$debtor = (string) array_key_first( $debtors );
				if ( 1 === count( $lenders_of[ $debtor ] ?? [] ) ) {
					$checks[]          = [ 'loan_receivable', 'loan_payable', 'préstamo intercompañía', [ $lender ], [ $debtor ], null ];
					$paired[ $lender ] = true;
					$paired[ $debtor ] = true;
				}
			}
		}
		$rest_r = array_values( array_diff( array_keys( $usd['loan_receivable'] ?? [] ), array_keys( $paired ) ) );
		$rest_p = array_values( array_diff( array_keys( $usd['loan_payable'] ?? [] ), array_keys( $paired ) ) );
		if ( $rest_r || $rest_p ) {
			$checks[] = [ 'loan_receivable', 'loan_payable', 'préstamos intercompañía', $rest_r, $rest_p, self::GROUP_COMPANY ];
		}
		foreach ( $checks as [ $rr, $pr, $label, $creditors, $debtors, $company ] ) {
			$bad = [];
			for ( $m = 0; $m <= 12; $m++ ) {
				$a = 0.0;
				$b = 0.0;
				foreach ( $creditors as $c ) {
					$a += $usd[ $rr ][ $c ][ $m ] ?? 0.0;
				}
				foreach ( $debtors as $d ) {
					$b += $usd[ $pr ][ $d ][ $m ] ?? 0.0;
				}
				if ( 0.0 === $a && 0.0 === $b ) {
					continue;
				}
				$skip = false;
				foreach ( array_merge( $creditors, $debtors ) as $x ) {
					$skip = $skip || isset( $flag[ $x ]['balance'][ $m ] );
				}
				$ok     = abs( $a - $b ) <= self::TOL_BALANCE * max( abs( $a ), abs( $b ) );
				// Diferencia de tiempo: la explica una venta en tránsito del mes o del siguiente (ya reportada como IC_TIMING).
				$timing = ! $ok && 'ic_receivable' === $rr && self::timing_explains( $pairs, $m, $a - $b );
				if ( $skip || $timing ) {
					$rows[] = [ 'kind' => $rr, 'creditors' => $creditors, 'debtors' => $debtors, 'month' => $m, 'receivable_usd' => $a, 'payable_usd' => $b, 'ok' => $ok, 'note' => $skip ? 'mes con error de lectura o escala' : 'tiempo' ];
					continue;
				}
				$rows[] = [ 'kind' => $rr, 'creditors' => $creditors, 'debtors' => $debtors, 'month' => $m, 'receivable_usd' => $a, 'payable_usd' => $b, 'ok' => $ok ];
				if ( ! $ok ) {
					$bad[ $m ] = [ $a, $b ];
				}
			}
			if ( ! $bad ) {
				continue;
			}
			$m0        = (int) array_key_first( $bad );
			[ $a, $b ] = $bad[ $m0 ];
			$targets   = $company ? [ $company ] : array_merge( $creditors, $debtors );
			foreach ( $targets as $t ) {
				$src      = $srcs[ $rr ][ $t ][ $m0 ] ?? ( $srcs[ $pr ][ $t ][ $m0 ] ?? null );
				$issues[] = Support::issue( 'IC_BALANCE', Support::CRITICAL, 'intercompania', Support::at( $src ) + [
					'company' => $t, 'version' => $version, 'month' => $m0, 'concept' => $roles[ $rr ], 'actual' => $a, 'expected' => $b, 'impact_usd' => abs( $a - $b ),
				], sprintf( 'Los %s no cuadran en %d de 13 cierres; en %s: por cobrar %s USD (%s) y por pagar %s USD (%s).',
					$label, count( $bad ), Support::month_name( $m0 ), Support::fmt( $a, 0 ), implode( ', ', $creditors ) ?: '—', Support::fmt( $b, 0 ), implode( ', ', $debtors ) ?: '—' ) );
			}
		}
		return [ 'rows' => $rows, 'issues' => $issues ];
	}

	/** ¿La diferencia de saldos del cierre $m la explican pares en tiempo del mes $m o $m + 1? */
	private static function timing_explains( array $pairs, int $m, float $diff ): bool {
		$t = 0.0;
		$n = 0;
		foreach ( $pairs as $p ) {
			if ( 'tiempo' === $p['status'] && in_array( (int) $p['month'], [ $m, $m + 1 ], true ) ) {
				$t += abs( (float) $p['diff_usd'] );
				$n++;
			}
		}
		return $n > 0 && abs( abs( $diff ) - $t ) <= self::TOL_BALANCE * max( $t, abs( $diff ) );
	}

	/* ------------------------------------------------------------------ apoyo */

	private static function usd( array $l, array $corp, string $version ): float {
		$r = Fx::try_rate( $corp, $version, $l['currency'], max( 1, $l['month'] ), 'avg' );
		return $r ? $l['amount'] / $r : $l['amount'];
	}

	private static function codes( array $datasets ): array {
		$out = [];
		foreach ( $datasets as $k => $ds ) {
			$out[] = (string) ( $ds['meta']['company'] ?? $k );
		}
		return $out;
	}

	/** Código de empresa a partir de código («GT») o nombre («Meridia Guatemala», «Guatemala»). */
	public static function company_code( string $cp, array $corp, array $known ): ?string {
		$up = strtoupper( trim( $cp ) );
		if ( '' === $up ) {
			return null;
		}
		if ( in_array( $up, $known, true ) ) {
			return $up;
		}
		$p = Support::plain( $cp );
		foreach ( (array) ( $corp['companies'] ?? [] ) as $code => $co ) {
			foreach ( [ $co['name'] ?? '', $co['country'] ?? '' ] as $n ) {
				$n = Support::plain( (string) $n );
				if ( '' !== $n && ( $p === $n || str_contains( $p, $n ) || str_contains( $n, $p ) ) ) {
					return (string) $code;
				}
			}
		}
		return null;
	}

	/** Línea normalizada al nombre del maestro (Snacks, Bebidas, Confitería). */
	public static function line( string $line, array $lines ): ?string {
		$p = Support::plain( $line );
		if ( '' === $p ) {
			return null;
		}
		$p = self::LINE_ALIASES[ $p ] ?? $p;
		foreach ( $lines as $l ) {
			if ( Support::plain( $l ) === $p ) {
				return $l;
			}
		}
		return $line;
	}
}
