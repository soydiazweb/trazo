<?php
/**
 * Reglas por archivo que no son de lectura (CONTRACT §7): escala, cálculos y totales, signo, tipo de cambio e
 * indicadores (WACC). Recibe un Dataset §6 y el Corporate §4; devuelve Issues §7.
 *
 * Genérico: líneas, cuentas y tolerancias salen del Corporate (catálogo con tipo/subtipo/operativa/intercompañía).
 * PHP puro: sin funciones de WordPress.
 */

namespace Consolida\Engine;

final class Validator {

	public const CRITICAL = Support::CRITICAL;
	public const WARNING  = Support::WARNING;

	/** Tolerancias del contrato. */
	public const TOL_VOLPRICE = 0.005;   // 0,5 %
	public const TOL_SPLIT    = 0.0001;  // 0,01 % (o 1 unidad del archivo)
	public const TOL_DETAIL   = 0.001;   // 0,1 %
	public const TOL_TOTAL    = 1.0;     // 1 unidad del archivo
	public const TOL_BS       = 0.001;   // 0,1 %
	public const TOL_DEPR     = 0.01;    // 1 %
	public const SCALE_FACTOR = 500.0;   // ≥ 500× o ≤ 1/500
	public const TOL_FX_USED  = 0.005;   // 0,5 %
	public const TOL_WACC     = 0.005;   // puntos porcentuales

	/** Textos cortos de cada código (para /meta issueCodes y el panel). */
	public const CODES = [
		'FMT_SCALE_DECL'  => 'Escala declarada distinta a la del corporativo',
		'FMT_SCALE_JUMP'  => 'Salto de escala (×1000)',
		'FMT_TEXT_NUMBER' => 'Números guardados como texto',
		'FMT_DATE'        => 'Fecha o periodo fuera de la versión',
		'CAT_UNKNOWN'     => 'Etiqueta sin mapeo al catálogo',
		'CAT_PRODUCT'     => 'Producto fuera del maestro',
		'CAT_CHANNEL'     => 'Canal fuera del maestro',
		'DATA_EMPTY'      => 'Celda vacía',
		'DATA_DUP'        => 'Concepto repetido en el mes',
		'CALC_VOLPRICE'   => 'Ventas ≠ volumen × precio',
		'CALC_SPLIT'      => 'Ventas netas ≠ terceros + intercompañía',
		'CALC_DETAIL'     => 'Detalle de ventas no cuadra',
		'CALC_TOTAL'      => 'Total ≠ suma del detalle',
		'CALC_BS'         => 'Balance descuadrado',
		'CALC_DEPR'       => 'Depreciación no cuadra con la acumulada',
		'SIGN'            => 'Signo contrario al esperado',
		'FX_USED'         => 'Tipo de cambio usado ≠ oficial',
		'FX_IC_CURRENCY'  => 'Intercompañía en moneda distinta de USD',
		'IC_MISMATCH'     => 'Intercompañía no cuadra',
		'IC_MISSING'      => 'Intercompañía sin contraparte',
		'IC_TIMING'       => 'Diferencia de tiempo intercompañía',
		'IC_PL_TIE'       => 'Resultados ≠ hoja intercompañía',
		'IC_BALANCE'      => 'Saldos intercompañía no cuadran',
		'KPI_WACC'        => 'WACC reportado ≠ WACC del corporativo',
		'KPI_COPY_EBIT'   => 'EBIT reportado ≠ calculado',
		'KPI_COPY_NOPAT'  => 'NOPAT reportado ≠ calculado',
		'KPI_COPY_NOWC'   => 'Capital de trabajo reportado ≠ calculado',
		'KPI_COPY_NFA'    => 'Activo fijo neto reportado ≠ calculado',
		'KPI_COPY_CI'     => 'Capital invertido reportado ≠ calculado',
		'KPI_COPY_CE'     => 'Capital empleado reportado ≠ calculado',
		'KPI_COPY_ROIC'   => 'ROIC reportado ≠ calculado',
		'KPI_COPY_ROCE'   => 'ROCE reportado ≠ calculado',
		'KPI_COPY_EVA'    => 'EVA reportado ≠ calculado',
	];

	/**
	 * @param array $ds  Dataset §6.
	 * @param array $ctx ['corporate'=>Corporate §4, 'version'=>clave (opcional; por defecto meta.version),
	 *                   'others'=>[versión=>Dataset del mismo país] (opcional, para FMT_SCALE_JUMP entre versiones)].
	 * @return array Issues propios (no repite los de lectura que ya trae $ds['issues']).
	 */
	public static function validate( array $ds, array $ctx ): array {
		$c = self::context( $ds, $ctx );
		// Consecuencias: un mes con fecha mal puesta (FMT_DATE) o con salto de escala no se vuelve a reportar en las
		// reglas de cálculo que dependen de él (se reporta la causa, no cada efecto).
		$jumps            = self::scale_jumps( $ds, $c, (array) ( $ctx['others'] ?? [] ) );
		$c['bad_months']  = self::flagged_months( (array) ( $ds['issues'] ?? [] ), [ 'FMT_DATE' ] );
		$c['jump_months'] = self::flagged_months( $jumps, [ 'FMT_SCALE_JUMP' ] );
		[ $totals, $c['sign_proven'] ] = self::total_rows( $ds, $c );
		$issues = array_merge(
			self::scale_declared( $ds, $c ),
			self::empty_and_dup( $ds, $c ),
			self::sales( $ds, $c ),
			$totals,
			self::balance( $ds, $c ),
			self::depreciation( $ds, $c ),
			self::signs( $ds, $c ),
			$jumps,
			self::fx_used( $ds, $c ),
			self::ic_currency( $ds, $c ),
			self::wacc( $ds, $c )
		);
		$seen = [];
		foreach ( (array) ( $ds['issues'] ?? [] ) as $i ) {
			$seen[ Support::issue_key( $i ) ] = true;
		}
		return array_values( array_filter( $issues, fn( $i ) => ! isset( $seen[ Support::issue_key( $i ) ] ) ) );
	}

	/**
	 * Datos de trabajo comunes (empresa, moneda, escala, roles, grids).
	 */
	public static function context( array $ds, array $ctx ): array {
		$corp    = (array) ( $ctx['corporate'] ?? [] );
		$meta    = (array) ( $ds['meta'] ?? [] );
		$company = (string) ( $meta['company'] ?? '' );
		$version = (string) ( $ctx['version'] ?? ( $meta['version'] ?? '' ) );
		$lines   = Support::lines( $corp );
		foreach ( (array) ( $ds['pl'] ?? [] ) as $r ) {
			if ( preg_match( '/^(?:net_sales|volume|price):(.+)$/', (string) ( $r['concept'] ?? '' ), $m ) && ! in_array( $m[1], $lines, true ) ) {
				$lines[] = $m[1];
			}
		}
		return [
			'corp'     => $corp,
			'company'  => $company,
			'version'  => $version,
			'currency' => Support::currency( $corp, $company, $ds ),
			'scale'    => max( 1.0, (float) ( $meta['scale_applied'] ?? 1 ) ),
			'catalog'  => (array) ( $corp['catalog'] ?? [] ),
			'roles'    => Support::roles( $corp ),
			'lines'    => $lines,
			'pl'       => Support::grid( (array) ( $ds['pl'] ?? [] ) ),
			'bs'       => Support::grid( (array) ( $ds['bs'] ?? [] ) ),
			'co'       => (array) ( $corp['companies'][ $company ] ?? [] ),
		];
	}

	/* ------------------------------------------------------------------ reglas */

	/** FMT_SCALE_DECL: la portada declara otra escala que parametros_paises.escala_archivo. */
	private static function scale_declared( array $ds, array $c ): array {
		$decl = self::scale_word( (string) ( $ds['meta']['scale_declared'] ?? '' ) );
		$exp  = self::scale_word( (string) ( $c['co']['scale'] ?? '' ) );
		if ( null === $decl || null === $exp || $decl === $exp ) {
			return [];
		}
		return [
			self::issue( 'FMT_SCALE_DECL', self::CRITICAL, 'formato_escala', $c, [ 'sheet' => 'portada' ],
				sprintf( 'La portada dice que los montos están en %s, pero el corporativo espera %s para %s.', $decl, $exp, $c['company'] ) ),
		];
	}

	/** DATA_EMPTY (celda vacía) y DATA_DUP (concepto repetido en el mes). */
	private static function empty_and_dup( array $ds, array $c ): array {
		$out = [];
		foreach ( [ 'pl', 'bs' ] as $part ) {
			foreach ( (array) ( $ds[ $part ] ?? [] ) as $r ) {
				if ( 'total_row' === ( $r['concept'] ?? '' ) || is_numeric( $r['value'] ?? null ) ) {
					continue;
				}
				$m     = (int) ( $r['month'] ?? 0 );
				$out[] = self::issue( 'DATA_EMPTY', self::CRITICAL, 'calculos_totales', $c,
					Support::at( $r['src'] ?? null ) + [ 'month' => $m, 'concept' => $r['concept'] ?? null ],
					sprintf( 'La celda de «%s» en %s está vacía.', $r['label'] ?? $r['concept'] ?? '', Support::month_name( $m ) ) );
			}
			foreach ( $c[ $part ] as $concept => $months ) {
				foreach ( $months as $m => $cell ) {
					if ( $cell['count'] > 1 && 'total_row' !== $concept ) {
						$out[] = self::issue( 'DATA_DUP', self::CRITICAL, 'catalogo_periodo', $c,
							Support::at( $cell['src'] ) + [ 'month' => $m, 'concept' => $concept ],
							sprintf( 'El concepto %s aparece %d veces en %s (%s).', $concept, $cell['count'], Support::month_name( $m ), 'pl' === $part ? 'resultados' : 'balance' ) );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * CALC_VOLPRICE, CALC_SPLIT y CALC_DETAIL por línea y mes. Se omiten los meses con fecha errónea (FMT_DATE) y,
	 * si ventas_detalle coincide con volumen × precio, el CALC_DETAIL de ese mes (la causa ya está en CALC_VOLPRICE).
	 * El cruce canal «Empresas del grupo» vs ventas intercompañía se agrupa en un issue por línea.
	 */
	private static function sales( array $ds, array $c ): array {
		$out    = [];
		$pl     = $c['pl'];
		$roles  = $c['roles'];
		$skip   = $c['bad_months']['resultados'] ?? [];
		$detail     = [];
		$c4         = [];
		$detail_bad = [];
		foreach ( (array) ( $ds['sales'] ?? [] ) as $s ) {
			$m = (int) ( $s['month'] ?? 0 );
			$l = (string) ( $s['line'] ?? '' );
			$detail[ $l ][ $m ] = ( $detail[ $l ][ $m ] ?? 0.0 ) + (float) ( $s['amount'] ?? 0 );
			if ( self::is_group_channel( (string) ( $s['channel'] ?? '' ), $c['corp'] ) ) {
				$c4[ $l ][ $m ] = ( $c4[ $l ][ $m ] ?? 0.0 ) + (float) ( $s['amount'] ?? 0 );
			}
		}
		foreach ( $c['lines'] as $line ) {
			$third  = $roles['sales_third'][ $line ] ?? null;
			$ic     = $roles['sales_ic'][ $line ] ?? null;
			$c4_bad = [];
			for ( $m = 1; $m <= 12; $m++ ) {
				$net_cell = $pl[ 'net_sales:' . $line ][ $m ] ?? null;
				$net      = is_array( $net_cell ) ? $net_cell['value'] : null;
				$vol      = $pl[ 'volume:' . $line ][ $m ]['value'] ?? null;
				$price    = $pl[ 'price:' . $line ][ $m ]['value'] ?? null;
				$rate     = self::avg( $c, $m );
				$tol      = fn( float $base ) => max( self::TOL_TOTAL * $c['scale'], self::TOL_DETAIL * abs( $base ) );
				$vp       = null;
				// Volumen × precio.
				if ( null !== $net && null !== $vol && null !== $price ) {
					$vp = $vol * $price;
					if ( abs( $net - $vp ) <= self::TOL_VOLPRICE * max( abs( $vp ), abs( $net ) ) ) {
						$vp = null;
					} else {
						$d     = $detail[ $line ][ $m ] ?? null;
						$agree = null !== $d && abs( $d - $vp ) <= $tol( $vp );
						$out[] = self::issue( 'CALC_VOLPRICE', self::CRITICAL, 'calculos_totales', $c,
							Support::at( $net_cell['src'] ) + [ 'month' => $m, 'concept' => 'net_sales:' . $line, 'actual' => $net, 'expected' => $vp, 'impact_usd' => self::usd( $net - $vp, $rate ) ],
							sprintf( 'En %s, las ventas netas de %s son %s, pero volumen × precio da %s (%s × %s)%s.',
								Support::month_name( $m ), $line, Support::money( $net, $c['currency'] ), Support::money( $vp, $c['currency'] ),
								Support::fmt( $vol, 0 ), Support::fmt( $price, 2 ), $agree ? '; ventas_detalle también suma ' . Support::money( $d, $c['currency'] ) : '' ) );
						if ( ! $agree ) {
							$vp = null;
						}
					}
				}
				// Terceros + intercompañía.
				$has_split = isset( $pl[ $third ][ $m ] ) || isset( $pl[ $ic ][ $m ] );
				$split     = Support::val( $pl, (string) $third, $m ) + Support::val( $pl, (string) $ic, $m );
				if ( null !== $net && $has_split && abs( $net - $split ) > max( self::TOL_TOTAL * $c['scale'], self::TOL_SPLIT * abs( $net ) ) ) {
					$out[] = self::issue( 'CALC_SPLIT', self::CRITICAL, 'calculos_totales', $c,
						Support::at( $net_cell['src'] ) + [ 'month' => $m, 'concept' => 'net_sales:' . $line, 'actual' => $net, 'expected' => $split, 'impact_usd' => self::usd( $net - $split, $rate ) ],
						sprintf( 'En %s, las ventas netas de %s son %s, pero terceros + intercompañía suman %s.',
							Support::month_name( $m ), $line, Support::money( $net, $c['currency'] ), Support::money( $split, $c['currency'] ) ) );
				}
				// Detalle por producto y canal (si ya cuadra con volumen × precio, la causa está en CALC_VOLPRICE).
				$base = $net ?? ( $has_split ? $split : null );
				if ( null === $vp && null !== $base && isset( $detail[ $line ][ $m ] ) ) {
					$d = $detail[ $line ][ $m ];
					if ( abs( $d - $base ) > $tol( $base ) ) {
						$detail_bad[ $m ][ $line ] = [ $d, $base, self::first_sales_src( $ds, $line, $m ), $rate ];
					}
				}
				if ( ! isset( $skip[ $m ] ) && null !== $ic && isset( $pl[ $ic ][ $m ] ) && isset( $detail[ $line ][ $m ] ) ) {
					$g   = $c4[ $line ][ $m ] ?? 0.0;
					$icv = Support::val( $pl, $ic, $m );
					if ( abs( $g - $icv ) > $tol( max( abs( $icv ), abs( $g ) ) ) ) {
						$c4_bad[ $m ] = [ $g, $icv, $pl[ $ic ][ $m ]['src'] ];
					}
				}
			}
			if ( $c4_bad ) {
				$m0              = (int) array_key_first( $c4_bad );
				[ $g, $icv, $src ] = $c4_bad[ $m0 ];
				$impact          = 0.0;
				foreach ( $c4_bad as $m => [ $a, $b ] ) {
					$impact += (float) self::usd( $a - $b, self::avg( $c, $m ) );
				}
				$out[] = self::issue( 'CALC_DETAIL', self::CRITICAL, 'calculos_totales', $c,
					Support::at( $src ) + [ 'month' => $m0, 'concept' => $ic, 'actual' => $g, 'expected' => $icv, 'impact_usd' => $impact ],
					sprintf( 'El canal «Empresas del grupo» de %s no cuadra con las ventas intercompañía de resultados en %s; en %s: canal %s y resultados %s.',
						$line, self::months_text( array_keys( $c4_bad ) ), Support::month_name( $m0 ), Support::money( $g, $c['currency'] ), Support::money( $icv, $c['currency'] ) ) );
			}
		}
		// ventas_detalle ≠ ventas netas: un issue por mes con las líneas afectadas.
		ksort( $detail_bad );
		foreach ( $detail_bad as $m => $by_line ) {
			$first             = array_key_first( $by_line );
			[ $d, $base, $src ] = $by_line[ $first ];
			$impact            = 0.0;
			$parts             = [];
			foreach ( $by_line as $line => [ $dd, $bb, , $rate ] ) {
				$impact += (float) self::usd( $dd - $bb, $rate );
				$parts[] = sprintf( '%s %s vs %s', $line, Support::fmt( $dd, 0 ), Support::fmt( $bb, 0 ) );
			}
			$out[] = self::issue( 'CALC_DETAIL', self::CRITICAL, 'calculos_totales', $c,
				Support::at( $src ) + [ 'month' => $m, 'concept' => 1 === count( $by_line ) ? 'net_sales:' . $first : null, 'actual' => $d, 'expected' => $base, 'impact_usd' => $impact ],
				sprintf( 'En %s, ventas_detalle no suma las ventas netas de resultados en %d %s (detalle vs resultados, %s): %s.%s',
					Support::month_name( $m ), count( $by_line ), 1 === count( $by_line ) ? 'línea' : 'líneas', $c['currency'], implode( '; ', $parts ),
					isset( $skip[ $m ] ) ? ' Ese mes además tiene la fecha fuera de la versión.' : '' ) );
		}
		return $out;
	}

	/**
	 * CALC_TOTAL: filas «Total …» escritas a mano.
	 * CONTRATO: §7 no dice qué detalle suma la fila `total_row`; se compara con la suma de las cuentas de gasto
	 * operativo (subtipo gasto_operacion) del mismo mes, que es lo que contiene «Total Gastos» en los archivos reales.
	 * Si el total cuadra al sumar en positivo una cuenta negativa, el mensaje lo dice y SIGN lo menciona.
	 *
	 * @return array{0:array,1:array} [issues, signos probados [concepto][mes]]
	 */
	private static function total_rows( array $ds, array $c ): array {
		$out    = [];
		$proven = [];
		$opex   = [];
		foreach ( $c['catalog'] as $code => $a ) {
			if ( 'gasto_operacion' === ( $a['subtype'] ?? '' ) ) {
				$opex[] = (string) $code;
			}
		}
		foreach ( (array) ( $ds['pl'] ?? [] ) as $r ) {
			if ( 'total_row' !== ( $r['concept'] ?? '' ) || ! is_numeric( $r['value'] ?? null ) ) {
				continue;
			}
			$m   = (int) ( $r['month'] ?? 0 );
			$sum = 0.0;
			$abs = 0.0;
			$neg = [];
			foreach ( $opex as $code ) {
				$v    = Support::val( $c['pl'], $code, $m );
				$sum += $v;
				$abs += abs( $v );
				if ( $v < 0 ) {
					$neg[] = $code;
				}
			}
			$v   = (float) $r['value'];
			$tol = self::TOL_TOTAL * $c['scale'] + 1e-6;
			if ( abs( $v - $sum ) <= $tol ) {
				continue;
			}
			$why = '';
			if ( $neg && abs( $v - $abs ) <= $tol ) {
				foreach ( $neg as $code ) {
					$proven[ $code ][ $m ] = $r['label'] ?? 'Total';
				}
				$why = sprintf( ' La diferencia se explica porque %s viene en negativo en el detalle y el total la suma en positivo.', implode( ', ', $neg ) );
			}
			$out[] = self::issue( 'CALC_TOTAL', self::CRITICAL, 'calculos_totales', $c,
				Support::at( $r['src'] ?? null ) + [ 'month' => $m, 'concept' => 'total_row', 'actual' => $v, 'expected' => $sum, 'impact_usd' => self::usd( $v - $sum, self::avg( $c, $m ) ) ],
				sprintf( 'En %s, la fila «%s» dice %s y la suma del detalle da %s (diferencia %s).%s',
					Support::month_name( $m ), $r['label'] ?? 'Total', Support::money( $v, $c['currency'] ), Support::money( $sum, $c['currency'] ), Support::fmt( $v - $sum, 0 ), $why ) );
		}
		return [ $out, $proven ];
	}

	/** CALC_BS: activo (cuentas con signo − restan) = pasivo + patrimonio, cada mes (0,1 %). */
	private static function balance( array $ds, array $c ): array {
		$out = [];
		$bs  = $c['bs'];
		if ( ! $bs ) {
			return [];
		}
		$months = [];
		foreach ( $bs as $months_of ) {
			$months += array_fill_keys( array_keys( $months_of ), true );
		}
		ksort( $months );
		foreach ( array_keys( $months ) as $m ) {
			$assets = 0.0;
			$le     = 0.0;
			$src    = null;
			foreach ( $c['roles']['assets'] as $code ) {
				$v       = Support::val( $bs, $code, $m );
				$assets += Support::sign( $c['catalog'][ $code ] ) < 0 ? -abs( $v ) : $v; // 1502 resta
			}
			foreach ( $c['roles']['liabilities_equity'] as $code ) {
				$le  += Support::val( $bs, $code, $m );
				$src  = $bs[ $code ][ $m ]['src'] ?? $src;
			}
			$base = max( abs( $assets ), abs( $le ) );
			if ( $base > 0 && abs( $assets - $le ) > self::TOL_BS * $base ) {
				$out[] = self::issue( 'CALC_BS', self::CRITICAL, 'calculos_totales', $c,
					Support::at( $src ) + [ 'month' => $m, 'actual' => $assets, 'expected' => $le, 'impact_usd' => self::usd( $assets - $le, self::close( $c, $m ) ) ],
					sprintf( 'En %s el balance no cuadra: activos %s y pasivo + patrimonio %s (diferencia %s).',
						Support::month_name( $m ), Support::money( $assets, $c['currency'] ), Support::money( $le, $c['currency'] ), Support::fmt( $assets - $le, 0 ) ) );
			}
		}
		return $out;
	}

	/** CALC_DEPR: variación de la depreciación acumulada = depreciación del mes (1 %). Aviso. */
	private static function depreciation( array $ds, array $c ): array {
		$acc = null;
		$exp = null;
		foreach ( $c['catalog'] as $code => $a ) {
			if ( 'activo_fijo' === ( $a['subtype'] ?? '' ) && Support::sign( $a ) < 0 ) {
				$acc ??= (string) $code;
			}
			if ( 'depreciacion' === ( $a['subtype'] ?? '' ) ) {
				$exp ??= (string) $code;
			}
		}
		if ( null === $acc || null === $exp || ! isset( $c['bs'][ $acc ] ) ) {
			return [];
		}
		$out = [];
		for ( $m = 1; $m <= 12; $m++ ) {
			if ( ! isset( $c['bs'][ $acc ][ $m ], $c['bs'][ $acc ][ $m - 1 ], $c['pl'][ $exp ][ $m ] ) ) {
				continue;
			}
			$j = $c['jump_months']['*'] ?? [];
			if ( isset( $j[ $m ] ) || isset( $j[ $m - 1 ] ) || isset( $c['bad_months']['*'][ $m ] ) ) {
				continue; // consecuencia de un salto de escala o de una fecha errónea
			}
			$delta = abs( Support::val( $c['bs'], $acc, $m ) ) - abs( Support::val( $c['bs'], $acc, $m - 1 ) );
			$dep   = Support::val( $c['pl'], $exp, $m );
			if ( abs( $delta - $dep ) > max( self::TOL_DEPR * abs( $dep ), self::TOL_TOTAL * $c['scale'] ) ) {
				$out[] = self::issue( 'CALC_DEPR', self::WARNING, 'calculos_totales', $c,
					Support::at( $c['pl'][ $exp ][ $m ]['src'] ) + [ 'month' => $m, 'concept' => $exp, 'actual' => $dep, 'expected' => $delta, 'impact_usd' => self::usd( $dep - $delta, self::avg( $c, $m ) ) ],
					sprintf( 'En %s la depreciación del mes es %s, pero la depreciación acumulada sube %s.',
						Support::month_name( $m ), Support::money( $dep, $c['currency'] ), Support::money( $delta, $c['currency'] ) ) );
			}
		}
		return $out;
	}

	/**
	 * SIGN: montos negativos en cuentas con signo esperado +. Un aviso por concepto (con los meses afectados).
	 * CONTRATO: §3 dice que 1502 (signo −) se guarda en positivo; si el archivo la trae negativa no es error.
	 */
	private static function signs( array $ds, array $c ): array {
		$out = [];
		foreach ( [ 'pl', 'bs' ] as $part ) {
			foreach ( $c[ $part ] as $concept => $months ) {
				$acc = $c['catalog'][ $concept ] ?? null;
				if ( ! $acc || Support::sign( $acc ) < 0 ) {
					continue;
				}
				$neg = array_filter( $months, fn( $cell ) => null !== $cell['value'] && $cell['value'] < -1e-9 );
				if ( ! $neg ) {
					continue;
				}
				$first  = (int) array_key_first( $neg );
				$cell   = $neg[ $first ];
				$proof  = $c['sign_proven'][ $concept ][ $first ] ?? null;
				$impact = 0.0;
				foreach ( $neg as $m => $x ) {
					$impact += 2 * abs( $x['value'] ) / ( ( 'pl' === $part ? self::avg( $c, (int) $m ) : self::close( $c, (int) $m ) ) ?: 1.0 );
				}
				$out[] = self::issue( 'SIGN', self::WARNING, 'catalogo_periodo', $c,
					Support::at( $cell['src'] ) + [ 'month' => $first, 'concept' => (string) $concept, 'actual' => $cell['value'], 'expected' => abs( $cell['value'] ), 'impact_usd' => $proof ? $impact : null ],
					sprintf( 'La cuenta %s (%s) tiene saldo negativo en %d %s (primero en %s: %s); se espera positivo.%s',
						$concept, $acc['name'] ?? '', count( $neg ), 1 === count( $neg ) ? 'mes' : 'meses', Support::month_name( $first ), Support::money( $cell['value'], $c['currency'] ),
						$proof ? sprintf( ' La fila «%s» del mes la suma en positivo: el signo está mal (ver CALC_TOTAL).', $proof ) : '' ) );
			}
		}
		return $out;
	}

	/**
	 * FMT_SCALE_JUMP: (a) un mes ≥ 500× o ≤ 1/500 de la mediana de los demás meses del mismo concepto;
	 * (b) el total anual de un concepto frente a otra versión del mismo país (si salta la mayoría de conceptos, un
	 * solo issue para el archivo).
	 */
	private static function scale_jumps( array $ds, array $c, array $others ): array {
		$out     = [];
		$flagged = [];
		$hits    = [];   // [parte][mes][] = datos del salto
		foreach ( [ 'pl', 'bs' ] as $part ) {
			foreach ( $c[ $part ] as $concept => $months ) {
				if ( str_starts_with( (string) $concept, 'price:' ) || 'total_row' === $concept ) {
					continue;
				}
				$vals = [];
				foreach ( $months as $m => $cell ) {
					if ( null !== $cell['value'] && abs( $cell['value'] ) > 0 ) {
						$vals[ $m ] = abs( $cell['value'] );
					}
				}
				if ( count( $vals ) < 4 ) {
					continue;
				}
				foreach ( $vals as $m => $v ) {
					$rest = $vals;
					unset( $rest[ $m ] );
					$med   = self::median( array_values( $rest ) );
					$ratio = $v / $med;
					if ( $ratio >= self::SCALE_FACTOR || $ratio <= 1 / self::SCALE_FACTOR ) {
						$k = (int) round( log10( $med / $v ) / 3 );
						$hits[ $part ][ $m ][] = [ 'concept' => (string) $concept, 'cell' => $months[ $m ], 'k' => $k, 'med' => $med ];
						$flagged[ $concept ]   = true;
					}
				}
			}
		}
		foreach ( $hits as $part => $by_month ) {
			foreach ( $by_month as $m => $list ) {
				$sheet = 'pl' === $part ? 'resultados' : 'balance';
				$rate  = 'pl' === $part ? self::avg( $c, $m ) : self::close( $c, $m );
				$imp   = 0.0;
				foreach ( $list as $h ) {
					if ( ! str_contains( $h['concept'], ':' ) ) {
						$imp += (float) self::usd( $h['cell']['value'] * ( 1000 ** $h['k'] ) - $h['cell']['value'], $rate );
					}
				}
				// Un saldo de balance de un mes intermedio no entra en los indicadores (capital promedio = saldo inicial y
				// de diciembre): el error rompe el mes, pero su impacto en US$ no se suma como si fuera real.
				if ( 'bs' === $part && $m > 0 && $m < 12 ) {
					$imp = null;
				}
				$h0   = $list[0];
				$word = $h0['k'] > 0 ? 'miles en vez de unidades' : 'unidades en vez de miles';
				if ( count( $list ) >= 3 ) {
					// Todo el mes en otra escala: un solo hallazgo.
					$out[] = self::issue( 'FMT_SCALE_JUMP', self::CRITICAL, 'formato_escala', $c,
						Support::at( $h0['cell']['src'] ) + [ 'month' => $m, 'actual' => $h0['cell']['value'], 'expected' => $h0['cell']['value'] * ( 1000 ** $h0['k'] ), 'impact_usd' => $imp ],
						sprintf( 'En %s, %d conceptos de %s están unas 1,000 veces fuera de escala frente a los demás meses (p. ej. «%s»: %s contra %s): el mes parece cargado en %s.',
							Support::month_name( $m ), count( $list ), $sheet, $h0['cell']['label'] ?? $h0['concept'], Support::fmt( $h0['cell']['value'], 0 ), Support::fmt( $h0['med'], 0 ), $word ) );
					continue;
				}
				foreach ( $list as $h ) {
					$cell  = $h['cell'];
					$exp   = $cell['value'] * ( 1000 ** $h['k'] );
					$out[] = self::issue( 'FMT_SCALE_JUMP', self::CRITICAL, 'formato_escala', $c,
						Support::at( $cell['src'] ) + [ 'month' => $m, 'concept' => $h['concept'], 'actual' => $cell['value'], 'expected' => $exp,
							'impact_usd' => str_contains( $h['concept'], ':' ) ? null : self::usd( $exp - $cell['value'], $rate ) ],
						sprintf( '«%s» en %s vale %s y en los demás meses ronda %s: parece cargado en %s.',
							self::label( $cell['label'] ?? null, $h['concept'] ), Support::month_name( $m ), Support::fmt( $cell['value'], 0 ), Support::fmt( $h['med'], 0 ), $h['k'] > 0 ? 'miles en vez de unidades' : 'unidades en vez de miles' ) );
				}
			}
		}
		// (b) contra otras versiones del mismo país.
		$mine = self::annual( $c['pl'], $c['bs'], $c['catalog'] );
		foreach ( $others as $ver => $other ) {
			if ( ! is_array( $other ) || (string) $ver === $c['version'] ) {
				continue;
			}
			$theirs = self::annual( Support::grid( (array) ( $other['pl'] ?? [] ) ), Support::grid( (array) ( $other['bs'] ?? [] ) ), $c['catalog'] );
			$jumps  = [];
			$cmp    = 0;
			foreach ( $mine as $concept => $v ) {
				$o = $theirs[ $concept ] ?? null;
				if ( ! $o || ! $v ) {
					continue;
				}
				$cmp++;
				$ratio = abs( $v ) / abs( $o );
				if ( $ratio >= self::SCALE_FACTOR || $ratio <= 1 / self::SCALE_FACTOR ) {
					$jumps[ $concept ] = $ratio;
				}
			}
			if ( ! $jumps ) {
				continue;
			}
			if ( $cmp > 0 && count( $jumps ) >= $cmp / 2 ) {
				$r     = (float) array_values( $jumps )[0];
				$out[] = self::issue( 'FMT_SCALE_JUMP', self::CRITICAL, 'formato_escala', $c, [ 'sheet' => 'resultados' ],
					sprintf( '%d de %d conceptos son unas %s veces los de %s: el archivo completo parece estar en otra escala.',
						count( $jumps ), $cmp, Support::fmt( $r >= 1 ? $r : 1 / $r, 0 ), Support::version_label( (string) $ver ) ) );
				break;
			}
			foreach ( $jumps as $concept => $ratio ) {
				if ( isset( $flagged[ $concept ] ) ) {
					continue;
				}
				$flagged[ $concept ] = true;
				$cell  = self::first_cell( $c['pl'][ $concept ] ?? $c['bs'][ $concept ] ?? [] );
				$out[] = self::issue( 'FMT_SCALE_JUMP', self::CRITICAL, 'formato_escala', $c,
					Support::at( $cell['src'] ?? null ) + [ 'concept' => (string) $concept ],
					sprintf( 'La cuenta %s suma %s en el año, unas %s veces lo de %s: posible error de escala.',
						$concept, Support::money( $mine[ $concept ], $c['currency'] ), Support::fmt( $ratio >= 1 ? $ratio : 1 / $ratio, 0 ), Support::version_label( (string) $ver ) ) );
			}
		}
		return $out;
	}

	/** FX_USED: tipo de cambio de indicadores_reportados vs promedio oficial anual de la versión (0,5 %). */
	private static function fx_used( array $ds, array $c ): array {
		$rep  = $ds['reported'] ?? null;
		$used = is_array( $rep ) ? ( $rep['fx_used'] ?? null ) : null;
		if ( ! is_numeric( $used ) || (float) $used <= 0 ) {
			return [];
		}
		$official = Fx::annual_avg( $c['corp'], $c['version'], $c['currency'] );
		if ( ! $official ) {
			return [];
		}
		$used = (float) $used;
		$diff = abs( $used - $official ) / $official;
		if ( $diff <= self::TOL_FX_USED ) {
			return [];
		}
		$eva    = is_numeric( $rep['eva_local'] ?? null ) ? (float) $rep['eva_local'] : null;
		$impact = null === $eva ? null : $eva / $used - $eva / $official;
		return [
			self::issue( 'FX_USED', self::CRITICAL, 'tipo_cambio', $c,
				Support::at( $rep['rows']['fx_used'] ?? null ) + [ 'concept' => 'fx_used', 'actual' => $used, 'expected' => $official, 'impact_usd' => $impact ],
				sprintf( 'El país usó un tipo de cambio de %s %s/USD y la tasa oficial promedio de %s es %s (diferencia %s).',
					Support::fmt( $used, 4 ), $c['currency'], Support::version_label( $c['version'] ), Support::fmt( $official, 4 ), Support::pct( $diff ) ) ),
		];
	}

	/** FX_IC_CURRENCY: operaciones intercompañía pactadas en moneda distinta de USD (un aviso por contraparte/tipo/moneda). */
	private static function ic_currency( array $ds, array $c ): array {
		$groups = [];
		foreach ( (array) ( $ds['ic'] ?? [] ) as $l ) {
			$cur = strtoupper( (string) ( $l['currency'] ?? 'USD' ) );
			if ( 'USD' === $cur || '' === $cur ) {
				continue;
			}
			$k = implode( '|', [ $l['counterparty'] ?? '', $l['type'] ?? '', $l['direction'] ?? '', $cur ] );
			$groups[ $k ][] = $l;
		}
		$out = [];
		foreach ( $groups as $lines ) {
			$l     = $lines[0];
			$sum   = array_sum( array_map( fn( $x ) => (float) ( $x['amount'] ?? 0 ), $lines ) );
			$out[] = self::issue( 'FX_IC_CURRENCY', self::WARNING, 'tipo_cambio', $c,
				Support::at( $l['src'] ?? null ) + [ 'month' => $l['month'] ?? null, 'concept' => 'ic:' . ( $l['type'] ?? '' ) ],
				sprintf( '%d %s con %s (%s) están pactadas en %s y no en USD (total %s %s); se convierten con la tasa oficial.',
					count( $lines ), 1 === count( $lines ) ? 'operación' : 'operaciones', $l['counterparty'] ?? '?', self::ic_type_name( (string) ( $l['type'] ?? '' ) ),
					strtoupper( (string) $l['currency'] ), Support::fmt( $sum, 0 ), strtoupper( (string) $l['currency'] ) ) );
		}
		return $out;
	}

	/** KPI_WACC: WACC reportado ≠ parametros_paises.wacc. */
	private static function wacc( array $ds, array $c ): array {
		$rep = $ds['reported'] ?? null;
		if ( ! is_array( $rep ) || ! is_numeric( $rep['wacc_pct'] ?? null ) || ! isset( $c['co']['wacc'] ) ) {
			return [];
		}
		$got = (float) $rep['wacc_pct'];
		$exp = (float) $c['co']['wacc'] * 100;
		if ( abs( $got - $exp ) <= self::TOL_WACC ) {
			return [];
		}
		$ci     = is_numeric( $rep['ic_avg'] ?? null ) ? (float) $rep['ic_avg'] : null;
		$fx     = is_numeric( $rep['fx_used'] ?? null ) && (float) $rep['fx_used'] > 0 ? (float) $rep['fx_used'] : ( Fx::annual_avg( $c['corp'], $c['version'], $c['currency'] ) ?: 1.0 );
		$impact = null === $ci ? null : abs( $got - $exp ) / 100 * $ci / $fx;
		return [
			self::issue( 'KPI_WACC', self::CRITICAL, 'indicadores', $c,
				Support::at( $rep['rows']['wacc_pct'] ?? null ) + [ 'concept' => 'wacc', 'actual' => $got, 'expected' => $exp, 'impact_usd' => $impact ],
				sprintf( 'El país reporta un WACC de %s %% y el corporativo fija %s %% para %s.', Support::fmt( $got, 1 ), Support::fmt( $exp, 1 ), $c['company'] ) ),
		];
	}

	/* ------------------------------------------------------------------ apoyo */

	/**
	 * Meses señalados por ciertos códigos (por hoja): ['resultados'=>[11=>true], 'balance'=>[4=>true], '*'=>[…]].
	 */
	public static function flagged_months( array $issues, array $codes ): array {
		$out = [ '*' => [] ];
		foreach ( $issues as $i ) {
			if ( in_array( $i['code'] ?? '', $codes, true ) && isset( $i['month'] ) && null !== $i['month'] ) {
				$out[ (string) ( $i['sheet'] ?? '' ) ][ (int) $i['month'] ] = true;
				$out['*'][ (int) $i['month'] ]                              = true;
			}
		}
		return $out;
	}

	/** «jul a dic» o «mar, may y sep». */
	public static function months_text( array $months ): string {
		sort( $months );
		$n = count( $months );
		if ( $n > 2 && $months[ $n - 1 ] - $months[0] === $n - 1 ) {
			return Support::month_name( $months[0] ) . ' a ' . Support::month_name( $months[ $n - 1 ] );
		}
		$names = array_map( fn( $m ) => Support::month_name( (int) $m ), $months );
		return $n > 1 ? implode( ', ', array_slice( $names, 0, -1 ) ) . ' y ' . end( $names ) : (string) ( $names[0] ?? '' );
	}

	/** «Etiqueta del archivo» (concepto) si son distintos. */
	private static function label( ?string $label, string $concept ): string {
		return null === $label || '' === $label || $label === $concept ? $concept : $label . ' (cuenta ' . $concept . ')';
	}

	/** Issue con empresa y versión del contexto. */
	private static function issue( string $code, string $sev, string $cat, array $c, array $f, string $msg ): array {
		return Support::issue( $code, $sev, $cat, $f + [ 'company' => $c['company'], 'version' => $c['version'] ], $msg );
	}

	private static function avg( array $c, int $m ): ?float {
		return Fx::try_rate( $c['corp'], $c['version'], $c['currency'], max( 1, $m ), 'avg' );
	}

	private static function close( array $c, int $m ): ?float {
		return Fx::try_rate( $c['corp'], $c['version'], $c['currency'], $m, 'close' );
	}

	private static function usd( float $local, ?float $rate ): ?float {
		return $rate ? abs( $local ) / $rate : null;
	}

	private static function median( array $v ): float {
		sort( $v );
		$n = count( $v );
		return $n % 2 ? $v[ intdiv( $n, 2 ) ] : ( $v[ $n / 2 - 1 ] + $v[ $n / 2 ] ) / 2;
	}

	/** Total anual por cuenta del catálogo: PL suma de meses, BS saldo de diciembre. */
	private static function annual( array $pl, array $bs, array $catalog ): array {
		$out = [];
		foreach ( $pl as $concept => $months ) {
			if ( isset( $catalog[ $concept ] ) ) {
				$out[ $concept ] = array_sum( array_map( fn( $x ) => (float) ( $x['value'] ?? 0 ), $months ) );
			}
		}
		foreach ( $bs as $concept => $months ) {
			if ( isset( $catalog[ $concept ] ) ) {
				$out[ $concept ] = (float) ( $months[12]['value'] ?? 0 );
			}
		}
		return $out;
	}

	private static function first_cell( array $months ): array {
		return $months ? $months[ array_key_first( $months ) ] : [];
	}

	private static function first_sales_src( array $ds, string $line, int $m ): ?array {
		foreach ( (array) ( $ds['sales'] ?? [] ) as $s ) {
			if ( (int) ( $s['month'] ?? 0 ) === $m && (string) ( $s['line'] ?? '' ) === $line ) {
				return $s['src'] ?? null;
			}
		}
		return null;
	}

	/** ¿Es el canal «Empresas del grupo»? (por código o nombre del maestro de canales). */
	public static function is_group_channel( string $channel, array $corp ): bool {
		$ch = Support::plain( $channel );
		if ( '' === $ch ) {
			return false;
		}
		foreach ( (array) ( $corp['channels'] ?? [] ) as $code => $info ) {
			$names = array_map( fn( $x ) => Support::plain( (string) $x ), [ $code, $info['name'] ?? '', $info['name_en'] ?? '' ] );
			if ( in_array( $ch, $names, true ) ) {
				return str_contains( $names[1], 'grupo' ) || str_contains( $names[2], 'group' );
			}
		}
		return str_contains( $ch, 'grupo' ) || str_contains( $ch, 'group' );
	}

	private static function scale_word( string $s ): ?string {
		$p = Support::plain( $s );
		if ( '' === $p ) {
			return null;
		}
		if ( str_contains( $p, 'miles' ) || str_contains( $p, 'thousand' ) || str_contains( $p, '000' ) ) {
			return 'miles';
		}
		if ( str_contains( $p, 'millon' ) || str_contains( $p, 'million' ) ) {
			return 'millones';
		}
		return 'unidades';
	}

	public static function ic_type_name( string $type ): string {
		return [
			'product_sale'   => 'venta de producto',
			'management_fee' => 'cuota de administración',
			'loan'           => 'préstamo',
			'loan_interest'  => 'intereses de préstamo',
		][ $type ] ?? $type;
	}
}
