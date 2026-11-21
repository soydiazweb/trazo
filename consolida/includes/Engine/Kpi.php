<?php
/**
 * Indicadores (CONTRACT §12): EBIT, NOPAT, capital de trabajo operativo neto, activo fijo neto, capital invertido
 * (CI), capital empleado (CE), ROIC, ROCE y EVA por país (USD y moneda local) y grupo; semáforo; KPI copiados por
 * los países (KPI_COPY_*); recálculo de las propuestas de inversión; impacto de cada issue.
 *
 * Promedio de capital: (saldo inicial + saldo final) / 2. Se determinó con los archivos: en los 2027 que cuadran
 * (p. ej. Guatemala v2 y Panamá v1/v2) el «Capital de trabajo operativo neto (promedio)» y el «Activo fijo neto
 * (promedio)» reportados coinciden al centavo con (inicial + diciembre) / 2, y no con el promedio de 13 saldos.
 *
 * PHP puro: sin funciones de WordPress.
 */

namespace Consolida\Engine;

final class Kpi {

	/** 'two_point' = (inicial + final) / 2 (lo que usan los países); 'thirteen' = promedio de 13 saldos. */
	public const AVERAGE_METHOD = 'two_point';

	public const LIGHT_MARGIN   = 0.01;   // 1 punto
	public const PROJECT_MARGIN = 0.02;   // 2 puntos
	public const COPY_AMOUNT    = 0.005;  // 0,5 %
	public const COPY_POINTS    = 0.2;    // 0,2 puntos
	private const EPS           = 1e-9;

	/** Indicadores reportados → código del issue, campo calculado y unidad. */
	public const REPORTED = [
		'ebit'          => [ 'KPI_COPY_EBIT', 'ebit', 'monto', 'EBIT' ],
		'nopat'         => [ 'KPI_COPY_NOPAT', 'nopat', 'monto', 'NOPAT' ],
		'nowc_avg'      => [ 'KPI_COPY_NOWC', 'nowc', 'monto', 'Capital de trabajo operativo neto' ],
		'net_fixed_avg' => [ 'KPI_COPY_NFA', 'nfa', 'monto', 'Activo fijo neto' ],
		'ic_avg'        => [ 'KPI_COPY_CI', 'ci', 'monto', 'Capital invertido' ],
		'ce_avg'        => [ 'KPI_COPY_CE', 'ce', 'monto', 'Capital empleado' ],
		'roic_pct'      => [ 'KPI_COPY_ROIC', 'roic', 'pct', 'ROIC' ],
		'roce_pct'      => [ 'KPI_COPY_ROCE', 'roce', 'pct', 'ROCE' ],
		'eva_local'     => [ 'KPI_COPY_EVA', 'eva', 'monto', 'EVA (moneda local)' ],
		'eva_usd'       => [ 'KPI_COPY_EVA', 'eva_usd', 'usd', 'EVA (USD)' ],
	];

	/**
	 * @param array $result    salida de Consolidator::run.
	 * @param array $corporate Corporate §4 (tasa de impuesto y WACC por país).
	 * @param array $issues    Issues abiertos (opcional) para el semáforo: cuenta los críticos con status ausente o
	 *                         'abierta'.
	 */
	public static function compute( array $result, array $corporate, array $issues = [] ): array {
		$roles = Support::roles( $corporate );
		$lines = [];
		foreach ( (array) ( $result['lines'] ?? [] ) as $l ) {
			$lines[ $l['account'] ] = $l;
		}
		$crit  = [];
		$total = 0;
		foreach ( $issues as $i ) {
			if ( ( $i['severity'] ?? '' ) === Support::CRITICAL && in_array( $i['status'] ?? 'abierta', [ 'abierta', null ], true ) ) {
				$crit[ (string) ( $i['company'] ?? '' ) ] = ( $crit[ (string) ( $i['company'] ?? '' ) ] ?? 0 ) + 1;
				$total++;
			}
		}

		$out   = [ 'average_method' => self::AVERAGE_METHOD, 'companies' => [], 'group' => [] ];
		$w_num = $w_den = $t_num = $t_den = 0.0;
		$waccs = [];
		foreach ( (array) ( $result['companies'] ?? array_keys( (array) ( $result['by_company'] ?? [] ) ) ) as $c ) {
			$co   = (array) ( $corporate['companies'][ $c ] ?? [] );
			$tax  = (float) ( $co['tax'] ?? 0 );
			$wacc = (float) ( $co['wacc'] ?? 0 );
			$bc   = (array) ( $result['by_company'][ $c ] ?? [] );
			$usd  = self::capital( $lines, $roles, fn( $l, $m ) => (float) ( $l['by_company_months'][ $c ][ $m ] ?? 0 ) );
			$loc  = self::capital( $lines, $roles, fn( $l, $m ) => (float) ( $l['local_months'][ $c ][ $m ] ?? 0 ) );
			$k_u  = self::indicators( (float) ( $bc['ebit'] ?? 0 ), $tax, $usd, $wacc );
			$k_l  = self::indicators( (float) ( $bc['local']['ebit'] ?? 0 ), $tax, $loc, $wacc );
			[ $light, $reasons ] = self::light( $k_u['roic'], $wacc, $crit[ $c ] ?? 0 );
			$out['companies'][ $c ] = $k_u + [
				'code'        => $c,
				'currency'    => $bc['currency'] ?? null,
				'revenue'     => (float) ( $bc['revenue'] ?? 0 ),
				'tax'         => $tax,
				'eva_usd'     => $k_u['eva'],
				'local'       => $k_l + [ 'revenue' => (float) ( $bc['local']['revenue'] ?? 0 ) ],
				'criticals'   => $crit[ $c ] ?? 0,
				'light'       => $light,
				'reasons'     => $reasons,
			];
			$waccs[] = $wacc;
			if ( $k_u['ci'] > 0 ) {
				$w_num += $wacc * $k_u['ci'];
				$w_den += $k_u['ci'];
			}
			$t_num += $tax * (float) ( $bc['ebit'] ?? 0 );
			$t_den += (float) ( $bc['ebit'] ?? 0 );
		}
		// Grupo: balance consolidado (sin intercompañía), WACC ponderado por CI.
		// CONTRATO: NOPAT del grupo = EBIT consolidado × (1 − tasa ponderada por EBIT de los países), para que la
		// eliminación del margen no realizado también afecte al NOPAT.
		$g_wacc = $w_den > 0 ? $w_num / $w_den : ( $waccs ? array_sum( $waccs ) / count( $waccs ) : 0.0 );
		$g_tax  = 0.0 !== $t_den ? $t_num / $t_den : 0.0;
		$g_cap  = self::capital( $lines, $roles, fn( $l, $m ) => (float) ( $l['months'][ $m ] ?? 0 ) );
		$g      = self::indicators( (float) ( $result['totals']['ebit'] ?? 0 ), $g_tax, $g_cap, $g_wacc );
		[ $light, $reasons ] = self::light( $g['roic'], $g_wacc, $total );
		$out['group'] = $g + [ 'code' => 'GRUPO', 'currency' => 'USD', 'revenue' => (float) ( $result['totals']['revenue'] ?? 0 ), 'tax' => $g_tax, 'eva_usd' => $g['eva'], 'criticals' => $total, 'light' => $light, 'reasons' => $reasons ];
		return $out;
	}

	/**
	 * Compara indicadores_reportados (§6 `reported`, en moneda local) con lo calculado y genera KPI_COPY_*.
	 * Para no contar un error dos veces, un indicador derivado (NOPAT, CI, ROIC, ROCE, EVA) solo se marca si tampoco
	 * cuadra con los otros valores reportados por el mismo país (si cuadra con ellos, el error está en el insumo).
	 *
	 * @param array $ds         Dataset §6.
	 * @param array $kpiCompany compute()['companies'][código].
	 * @param array $ctx        ['corporate'=>…, 'version'=>…].
	 * @return array{rows:array,issues:array}
	 */
	public static function copied( array $ds, array $kpiCompany, array $ctx ): array {
		$rep = $ds['reported'] ?? null;
		if ( ! is_array( $rep ) ) {
			return [ 'rows' => [], 'issues' => [] ];
		}
		$corp     = (array) ( $ctx['corporate'] ?? [] );
		$company  = (string) ( $ds['meta']['company'] ?? ( $kpiCompany['code'] ?? '' ) );
		$version  = (string) ( $ctx['version'] ?? ( $ds['meta']['version'] ?? '' ) );
		$cur      = Support::currency( $corp, $company, $ds );
		$official = Fx::annual_avg( $corp, $version, $cur ) ?: 1.0;
		$loc      = (array) ( $kpiCompany['local'] ?? [] );
		$calc     = [
			'ebit' => $loc['ebit'] ?? null, 'nopat' => $loc['nopat'] ?? null, 'nowc' => $loc['nowc'] ?? null, 'nfa' => $loc['nfa'] ?? null,
			'ci' => $loc['ci'] ?? null, 'ce' => $loc['ce'] ?? null, 'roic' => isset( $loc['roic'] ) ? $loc['roic'] * 100 : null,
			'roce' => isset( $loc['roce'] ) ? $loc['roce'] * 100 : null, 'eva' => $loc['eva'] ?? null,
			'eva_usd' => isset( $loc['eva'] ) ? $loc['eva'] / $official : null,
		];
		$r   = fn( $k ) => is_numeric( $rep[ $k ] ?? null ) ? (float) $rep[ $k ] : null;
		$fx  = $r( 'fx_used' );
		$tax = $r( 'tax_rate' );
		$tax = null === $tax ? null : ( $tax > 1 ? $tax / 100 : $tax );
		// Lo que darían los valores reportados entre sí.
		$derived = [
			'nopat'     => null !== $r( 'ebit' ) && null !== $tax ? $r( 'ebit' ) * ( 1 - $tax ) : null,
			'ic_avg'    => null !== $r( 'nowc_avg' ) && null !== $r( 'net_fixed_avg' ) ? $r( 'nowc_avg' ) + $r( 'net_fixed_avg' ) : null,
			'roic_pct'  => $r( 'nopat' ) !== null && $r( 'ic_avg' ) ? $r( 'nopat' ) / $r( 'ic_avg' ) * 100 : null,
			'roce_pct'  => $r( 'ebit' ) !== null && $r( 'ce_avg' ) ? $r( 'ebit' ) / $r( 'ce_avg' ) * 100 : null,
			'eva_local' => null !== $r( 'nopat' ) && null !== $r( 'wacc_pct' ) && null !== $r( 'ic_avg' ) ? $r( 'nopat' ) - $r( 'wacc_pct' ) / 100 * $r( 'ic_avg' ) : null,
			'eva_usd'   => null !== $r( 'eva_local' ) && $fx ? $r( 'eva_local' ) / $fx : null,
		];
		// Montos de resultados que el lector no pudo mapear (CAT_UNKNOWN): el país sí los incluye en su EBIT.
		$unknown = 0.0;
		foreach ( (array) ( $ds['issues'] ?? [] ) as $i ) {
			if ( 'CAT_UNKNOWN' === ( $i['code'] ?? '' ) && 'resultados' === ( $i['sheet'] ?? '' ) && is_numeric( $i['actual'] ?? null ) ) {
				$unknown += abs( (float) $i['actual'] );
			}
		}
		$rows   = [];
		$issues = [];
		foreach ( self::REPORTED as $field => [ $code, $key, $unit, $label ] ) {
			$got = $r( $field );
			$exp = $calc[ $key ];
			if ( null === $got || null === $exp ) {
				continue;
			}
			$bad_calc = self::differs( $got, (float) $exp, $unit );
			$explained = $bad_calc && isset( $derived[ $field ] ) && null !== $derived[ $field ] && ! self::differs( $got, $derived[ $field ], $unit );
			$by_unknown = $bad_calc && ! $explained && 'ebit' === $field && $unknown > 0 && abs( abs( $got - (float) $exp ) - $unknown ) <= max( 1.0, 0.005 * $unknown );
			$explained  = $explained || $by_unknown;
			$row = [
				'indicator' => $field, 'label' => $label, 'reported' => $got, 'calculated' => (float) $exp, 'diff' => $got - (float) $exp,
				'unit' => $unit, 'ok' => ! $bad_calc, 'explained_by_inputs' => $explained, 'explained_by' => $by_unknown ? 'CAT_UNKNOWN' : ( $explained ? 'insumos' : null ), 'src' => $rep['rows'][ $field ] ?? null,
			];
			$rows[] = $row;
			if ( ! $bad_calc || $explained ) {
				continue;
			}
			$impact = match ( $unit ) {
				'pct'   => abs( $got - (float) $exp ) / 100 * (float) ( $kpiCompany['ci'] ?? 0 ),
				'usd'   => abs( $got - (float) $exp ),
				default => abs( $got - (float) $exp ) / $official,
			};
			$fmt      = fn( $v ) => 'pct' === $unit ? Support::fmt( $v, 1 ) . ' %' : ( 'usd' === $unit ? Support::money( $v, 'USD' ) : Support::money( $v, $cur ) );
			$issues[] = Support::issue( $code, Support::CRITICAL, 'indicadores', Support::at( $row['src'] ) + [
				'company' => $company, 'version' => $version, 'concept' => $field, 'actual' => $got, 'expected' => (float) $exp, 'impact_usd' => $impact,
			], sprintf( '%s reportado: %s; calculado con sus propios estados: %s (diferencia %s).', $label, $fmt( $got ), $fmt( (float) $exp ),
				'pct' === $unit ? Support::fmt( $got - (float) $exp, 1 ) . ' puntos' : $fmt( $got - (float) $exp ) ) );
		}
		return [ 'rows' => $rows, 'issues' => $issues ];
	}

	/**
	 * Recalcula las propuestas de inversión P1..P4: ROIC = NOPAT incremental / capital del proyecto; EVA = NOPAT −
	 * WACC real de la sede × capital; revisa el WACC usado y que capital ≥ inversión; decisión recalculada.
	 */
	public static function proposals( array $corporate ): array {
		$out = [];
		foreach ( (array) ( $corporate['proposals'] ?? [] ) as $p ) {
			$site    = (string) ( $p['site'] ?? '' );
			$wacc    = isset( $corporate['companies'][ $site ]['wacc'] ) ? (float) $corporate['companies'][ $site ]['wacc'] : ( (float) ( $p['wacc_pct'] ?? 0 ) / 100 );
			$capital = (float) ( $p['capital_usd'] ?? 0 );
			$inv     = (float) ( $p['investment_usd'] ?? 0 );
			$nopat   = (float) ( $p['incremental_nopat_usd'] ?? 0 );
			$roic    = $capital > 0 ? $nopat / $capital : null;
			$eva     = $nopat - $wacc * $capital;
			$used    = (float) ( $p['wacc_pct'] ?? 0 ) / 100;
			$reasons = [];
			if ( null === $roic ) {
				$dec       = 'rechazar';
				$reasons[] = 'El capital del proyecto no es positivo.';
			} elseif ( $roic >= $wacc + self::PROJECT_MARGIN - self::EPS ) {
				$dec = 'aprobar';
			} elseif ( $roic >= $wacc - self::EPS ) {
				$dec = 'ajustar';
			} else {
				$dec = 'rechazar';
			}
			if ( null !== $roic ) {
				$reasons[] = sprintf( 'ROIC recalculado %s frente a WACC de %s %s (%s %s puntos).', Support::pct( $roic, 2 ), $site, Support::pct( $wacc ),
					$roic >= $wacc ? 'margen de' : 'faltan', Support::fmt( abs( $roic - $wacc ) * 100, 2 ) );
			}
			$wacc_ok = abs( $used - $wacc ) < 0.0001;
			if ( ! $wacc_ok ) {
				$reasons[] = sprintf( 'La propuesta usa un WACC de %s, pero el de %s es %s.', Support::pct( $used ), $site, Support::pct( $wacc ) );
			}
			$cap_ok = $capital + 0.005 >= $inv;
			if ( ! $cap_ok ) {
				$reasons[] = sprintf( 'El capital del proyecto (%s USD) es menor que la inversión (%s USD).', Support::fmt( $capital, 0 ), Support::fmt( $inv, 0 ) );
			}
			$roic_rep = isset( $p['roic_pct'] ) ? (float) $p['roic_pct'] : null;
			$roic_ok  = null === $roic_rep || null === $roic || abs( $roic_rep - $roic * 100 ) <= 0.05;
			if ( ! $roic_ok ) {
				$reasons[] = sprintf( 'El ROIC presentado (%s %%) no coincide con NOPAT / capital (%s).', Support::fmt( $roic_rep, 2 ), Support::pct( $roic, 2 ) );
			}
			$eva_rep = isset( $p['eva_usd'] ) ? (float) $p['eva_usd'] : null;
			$eva_ok  = null === $eva_rep || abs( $eva_rep - $eva ) <= max( 1.0, 0.005 * abs( $eva ) );
			if ( ! $eva_ok ) {
				$reasons[] = sprintf( 'El EVA presentado (%s USD) no coincide con el recalculado (%s USD).', Support::fmt( $eva_rep, 0 ), Support::fmt( $eva, 0 ) );
			}
			$proposed = Support::plain( (string) ( $p['decision'] ?? '' ) );
			$holds    = $proposed === $dec;
			$reasons[] = $holds
				? sprintf( 'La decisión «%s» se sostiene.', $dec )
				: sprintf( 'La decisión presentada es «%s», pero con las reglas del grupo corresponde «%s».', $proposed, $dec );
			$out[] = [
				'id'                    => $p['id'] ?? null,
				'site'                  => $site,
				'description'           => $p['description'] ?? null,
				'investment_usd'        => $inv,
				'capital_usd'           => $capital,
				'nopat_usd'             => $nopat,
				'roic_presented'        => null === $roic_rep ? null : $roic_rep / 100,
				'roic'                  => $roic,
				'wacc_presented'        => $used,
				'wacc'                  => $wacc,
				'eva_presented'         => $eva_rep,
				'eva'                   => $eva,
				'wacc_ok'               => $wacc_ok,
				'capital_ok'            => $cap_ok,
				'roic_ok'               => $roic_ok,
				'eva_ok'                => $eva_ok,
				'decision_propuesta'    => $proposed,
				'decision_recalculada'  => $dec,
				'holds'                 => $holds,
				'reasons'               => $reasons,
				'row'                   => $p['row'] ?? null,
			];
		}
		return $out;
	}

	/**
	 * Efecto aproximado en EBIT, ROIC y EVA (USD) de corregir un issue con `expected` en una cuenta de resultados.
	 *
	 * @param array $ctx ['corporate'=>…, 'version'=>…]
	 */
	public static function issue_impact( array $issue, array $kpiCompany, array $ctx ): array {
		$out = [ 'applies' => false, 'delta_ebit' => 0.0, 'delta_nopat' => 0.0, 'roic_before' => $kpiCompany['roic'] ?? null, 'roic_after' => $kpiCompany['roic'] ?? null, 'points' => 0.0, 'delta_eva' => 0.0, 'text' => '' ];
		$corp = (array) ( $ctx['corporate'] ?? [] );
		$acc  = $corp['catalog'][ (string) ( $issue['concept'] ?? '' ) ] ?? null;
		if ( ! is_numeric( $issue['expected'] ?? null ) || ! is_numeric( $issue['actual'] ?? null ) ) {
			$out['text'] = 'Sin valor esperado: no se puede estimar el impacto.';
			return $out;
		}
		if ( ! $acc || ! Support::is_pl( $acc ) || 0 === Support::pl_effect( $acc )['ebit'] ) {
			$out['text'] = 'No es una cuenta operativa de resultados: sin efecto directo en el ROIC.';
			return $out;
		}
		$company = (string) ( $issue['company'] ?? '' );
		$version = (string) ( $issue['version'] ?? ( $ctx['version'] ?? '' ) );
		$cur     = Support::currency( $corp, $company );
		$m       = (int) ( $issue['month'] ?? 0 );
		$rate    = ( $m > 0 ? Fx::try_rate( $corp, $version, $cur, $m, 'avg' ) : Fx::annual_avg( $corp, $version, $cur ) ) ?: 1.0;
		$tax     = (float) ( $kpiCompany['tax'] ?? ( $corp['companies'][ $company ]['tax'] ?? 0 ) );

		$d       = ( (float) $issue['expected'] - (float) $issue['actual'] ) / $rate * Support::pl_effect( $acc )['ebit'];
		$dn      = $d * ( 1 - $tax );
		$ci      = (float) ( $kpiCompany['ci'] ?? 0 );
		$out['applies']     = true;
		$out['delta_ebit']  = $d;
		$out['delta_nopat'] = $dn;
		$out['delta_eva']   = $dn;
		if ( $ci > 0 ) {
			$out['points']     = $dn / $ci * 100;
			$out['roic_after'] = null === $out['roic_before'] ? null : (float) $out['roic_before'] + $dn / $ci;
		}
		$out['text'] = sprintf( 'Si se corrige, el EBIT cambia %s USD, el EVA %s USD y el ROIC %s puntos.', Support::fmt( $d, 0 ), Support::fmt( $dn, 0 ), Support::fmt( $out['points'], 2 ) );
		return $out;
	}

	/**
	 * Semáforo: verde si ROIC ≥ WACC + 1 punto y sin críticos; rojo si ROIC < WACC − 1 punto o hay críticos;
	 * si no, amarillo.
	 *
	 * @return array{0:string,1:string[]}
	 */
	public static function light( ?float $roic, float $wacc, int $criticals ): array {
		$reasons = [];
		if ( $criticals > 0 ) {
			$reasons[] = 1 === $criticals ? 'Hay 1 error crítico abierto.' : sprintf( 'Hay %d errores críticos abiertos.', $criticals );
		}
		if ( null === $roic ) {
			$reasons[] = 'El capital invertido no es positivo: no se puede calcular el ROIC.';
			return [ $criticals > 0 ? 'rojo' : 'amarillo', $reasons ];
		}
		$spread = $roic - $wacc;
		if ( $spread >= self::LIGHT_MARGIN - self::EPS ) {
			$reasons[] = sprintf( 'El ROIC (%s) supera al WACC (%s) en %s puntos.', Support::pct( $roic ), Support::pct( $wacc ), Support::fmt( $spread * 100, 1 ) );
			$light     = 'verde';
		} elseif ( $spread < -self::LIGHT_MARGIN - self::EPS ) {
			$reasons[] = sprintf( 'El ROIC (%s) está %s puntos por debajo del WACC (%s).', Support::pct( $roic ), Support::fmt( -$spread * 100, 1 ), Support::pct( $wacc ) );
			$light     = 'rojo';
		} else {
			$reasons[] = sprintf( 'El ROIC (%s) está a menos de 1 punto del WACC (%s).', Support::pct( $roic ), Support::pct( $wacc ) );
			$light     = 'amarillo';
		}
		return [ $criticals > 0 ? 'rojo' : $light, $reasons ];
	}

	/* ------------------------------------------------------------------ apoyo */

	/**
	 * Capital por mes y promedio: CTON = activos de capital de trabajo − pasivos de capital de trabajo; activo fijo
	 * neto (las cuentas con signo − restan); CI = CTON + AFN; CE = activo total − pasivo corriente.
	 */
	public static function capital( array $lines, array $roles, callable $get ): array {
		$at = function ( array $codes, int $m ) use ( $lines, $get ): float {
			$s = 0.0;
			foreach ( $codes as $code ) {
				if ( isset( $lines[ $code ] ) ) {
					$s += ( $lines[ $code ]['sign'] ?? 1 ) * $get( $lines[ $code ], $m );
				}
			}
			return $s;
		};
		$series = [];
		for ( $m = 0; $m <= 12; $m++ ) {
			$nowc            = $at( $roles['wc_assets'], $m ) - $at( $roles['wc_liabilities'], $m );
			$nfa             = $at( $roles['fixed'], $m );
			$series[ $m ]    = [ 'nowc' => $nowc, 'nfa' => $nfa, 'ci' => $nowc + $nfa, 'ce' => $at( $roles['assets'], $m ) - $at( $roles['current_liabilities'], $m ) ];
		}
		$avg = [];
		foreach ( [ 'nowc', 'nfa', 'ci', 'ce' ] as $k ) {
			$avg[ $k ] = 'thirteen' === self::AVERAGE_METHOD
				? array_sum( array_column( $series, $k ) ) / 13
				: ( $series[0][ $k ] + $series[12][ $k ] ) / 2;
		}
		return $avg + [ 'series' => $series ];
	}

	private static function indicators( float $ebit, float $tax, array $cap, float $wacc ): array {
		$nopat = $ebit * ( 1 - $tax );
		return [
			'ebit'  => $ebit,
			'nopat' => $nopat,
			'nowc'  => $cap['nowc'],
			'nfa'   => $cap['nfa'],
			'ci'    => $cap['ci'],
			'ce'    => $cap['ce'],
			'roic'  => $cap['ci'] > 0 ? $nopat / $cap['ci'] : null,
			'roce'  => $cap['ce'] > 0 ? $ebit / $cap['ce'] : null,
			'wacc'  => $wacc,
			'eva'   => $nopat - $wacc * $cap['ci'],
		];
	}

	private static function differs( float $got, float $exp, string $unit ): bool {
		if ( 'pct' === $unit ) {
			return abs( $got - $exp ) > self::COPY_POINTS + self::EPS;
		}
		return abs( $got - $exp ) > self::COPY_AMOUNT * max( abs( $exp ), abs( $got ) ) + 0.01;
	}
}
