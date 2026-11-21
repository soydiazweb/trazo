<?php
/**
 * Módulos preventivo y evolutivo (CONTRACT §13): calidad del ciclo actual frente a historial_ciclos, serie de
 * indicadores (historial_indicadores + real + presupuestos) y escenarios de sensibilidad.
 *
 * PHP puro: sin funciones de WordPress.
 */

namespace Consolida\Engine;

final class History {

	/** Regla preventiva sugerida por categoría. */
	public const PREVENTIVE = [
		'formato_escala'   => 'Bloquear la carga si la escala de la portada no coincide con la del corporativo y comparar cada cuenta con la versión anterior antes de enviar.',
		'catalogo_periodo' => 'Usar solo etiquetas del perfil de lectura aprobado y validar fechas y meses contra la versión antes de subir.',
		'calculos_totales' => 'Quitar los totales escritos a mano y validar volumen × precio, detalle por canal y cuadre del balance antes de aprobar.',
		'intercompania'    => 'Confirmar cada mes con la contraparte las ventas, cuotas, intereses y saldos intercompañía (en USD) antes del cierre.',
		'tipo_cambio'      => 'Tomar siempre la tasa oficial publicada por el corporativo y pactar la intercompañía en USD.',
		'indicadores'      => 'No copiar indicadores: dejar que el sistema calcule ROIC, ROCE y EVA con el WACC del corporativo.',
	];

	/**
	 * Errores del ciclo actual por país y categoría junto al historial; ranking, tendencia y regla preventiva.
	 *
	 * @param array $issuesByCountry [código => [Issue…]] del ciclo actual.
	 * @param array $corporate       Corporate §4 (history_cycles).
	 */
	public static function quality( array $issuesByCountry, array $corporate ): array {
		$cats    = array_keys( Support::CATEGORIES );
		$history = [];
		foreach ( (array) ( $corporate['history_cycles'] ?? [] ) as $h ) {
			$c = (string) ( $h['company'] ?? '' );
			$errors = [];
			foreach ( $cats as $cat ) {
				$errors[ $cat ] = (int) ( $h['errors'][ $cat ] ?? 0 );
			}
			$history[ $c ][] = [
				'cycle'  => $h['cycle'] ?? ( $h['version'] ?? '' ),
				'year'   => $h['year'] ?? null,
				'errors' => $errors,
				'total'  => (int) ( $h['total_errors'] ?? array_sum( $errors ) ),
				'delay_days' => $h['delay_days'] ?? null,
				'resubmissions' => $h['resubmissions'] ?? null,
				'days_to_consolidate' => $h['days_to_consolidate'] ?? null,
				'analyst_hours' => $h['analyst_hours'] ?? null,
			];
		}
		$countries = array_values( array_unique( array_merge( array_map( 'strval', array_keys( $issuesByCountry ) ), array_keys( $history ), array_map( 'strval', array_keys( (array) ( $corporate['companies'] ?? [] ) ) ) ) ) );
		$rows      = [];
		foreach ( $countries as $c ) {
			$cur = array_fill_keys( $cats, 0 );
			$sev = [ Support::CRITICAL => 0, Support::WARNING => 0 ];
			foreach ( (array) ( $issuesByCountry[ $c ] ?? [] ) as $i ) {
				$cat = (string) ( $i['category'] ?? '' );
				if ( isset( $cur[ $cat ] ) ) {
					$cur[ $cat ]++;
				}
				$sev[ $i['severity'] ?? Support::WARNING ] = ( $sev[ $i['severity'] ?? Support::WARNING ] ?? 0 ) + 1;
			}
			$hist   = $history[ $c ] ?? [];
			$last   = $hist ? end( $hist ) : null;
			$total  = array_sum( $cur );
			$cum    = $cur;
			foreach ( $hist as $h ) {
				foreach ( $cats as $cat ) {
					$cum[ $cat ] += $h['errors'][ $cat ];
				}
			}
			arsort( $cum );
			$top = (string) array_key_first( $cum );
			$rows[ $c ] = [
				'company'     => $c,
				'current'     => $cur,
				'total'       => $total,
				'severity'    => $sev,
				'history'     => $hist,
				'last_total'  => $last['total'] ?? null,
				'trend'       => null === $last ? 'sin_historial' : ( $total > $last['total'] ? 'empeora' : ( $total < $last['total'] ? 'mejora' : 'igual' ) ),
				'top_category' => $top,
				'top_count'   => $cum[ $top ] ?? 0,
				'rule'        => self::PREVENTIVE[ $top ] ?? null,
				'message'     => sprintf( '%s: %d hallazgos en este ciclo (%s el ciclo anterior); lo que más se repite es «%s».',
					$c, $total, null === $last ? 'sin dato' : $last['total'] . ' en ' . $last['cycle'], Support::CATEGORIES[ $top ] ?? $top ),
			];
		}
		$ranking = array_values( $rows );
		usort( $ranking, fn( $a, $b ) => [ $b['total'], $a['company'] ] <=> [ $a['total'], $b['company'] ] );
		$by_cat = array_fill_keys( $cats, 0 );
		foreach ( $rows as $r ) {
			foreach ( $cats as $cat ) {
				$by_cat[ $cat ] += $r['current'][ $cat ];
			}
		}
		return [ 'countries' => $rows, 'ranking' => array_column( $ranking, 'company' ), 'by_category' => $by_cat, 'categories' => Support::CATEGORIES ];
	}

	/**
	 * Serie por país (y grupo) de ingresos, EBIT, NOPAT, CI, ROIC, WACC y EVA: historial 2023–2025 + versiones dadas.
	 *
	 * @param array $kpiByVersion [versión => salida de Kpi::compute].
	 */
	public static function kpi_trend( array $corporate, array $kpiByVersion ): array {
		$series = [];
		foreach ( (array) ( $corporate['history_kpis'] ?? [] ) as $h ) {
			$c = (string) ( $h['company'] ?? '' );
			$series[ $c ][] = [
				'label'   => ( $h['year'] ?? '' ) . ' ' . ( $h['kind'] ?? 'real' ),
				'year'    => $h['year'] ?? null,
				'source'  => 'historial',
				'revenue' => (float) ( $h['revenue_usd'] ?? 0 ),
				'ebit'    => (float) ( $h['ebit_usd'] ?? 0 ),
				'nopat'   => (float) ( $h['nopat_usd'] ?? 0 ),
				'ci'      => (float) ( $h['invested_capital_usd'] ?? 0 ),
				'roic'    => isset( $h['roic_pct'] ) ? (float) $h['roic_pct'] / 100 : null,
				'wacc'    => isset( $h['wacc_pct'] ) ? (float) $h['wacc_pct'] / 100 : null,
				'eva'     => (float) ( $h['eva_usd'] ?? 0 ),
			];
		}
		foreach ( $kpiByVersion as $version => $k ) {
			$items = (array) ( $k['companies'] ?? [] );
			if ( isset( $k['group'] ) ) {
				$items['GRUPO'] = $k['group'];
			}
			foreach ( $items as $c => $x ) {
				$series[ (string) $c ][] = [
					'label' => Support::version_label( (string) $version ), 'year' => Support::version_year( (string) $version ), 'source' => (string) $version,
					'revenue' => (float) ( $x['revenue'] ?? 0 ), 'ebit' => (float) ( $x['ebit'] ?? 0 ), 'nopat' => (float) ( $x['nopat'] ?? 0 ),
					'ci' => (float) ( $x['ci'] ?? 0 ), 'roic' => $x['roic'] ?? null, 'wacc' => $x['wacc'] ?? null, 'eva' => (float) ( $x['eva'] ?? 0 ),
				];
			}
		}
		// Grupo histórico = suma de países (ROIC = NOPAT / CI; WACC ponderado por CI).
		$hist_group = [];
		foreach ( (array) ( $corporate['history_kpis'] ?? [] ) as $h ) {
			$y = (string) ( $h['year'] ?? '' );
			$g = &$hist_group[ $y ];
			$g ??= [ 'revenue' => 0.0, 'ebit' => 0.0, 'nopat' => 0.0, 'ci' => 0.0, 'eva' => 0.0, 'wci' => 0.0 ];
			$g['revenue'] += (float) ( $h['revenue_usd'] ?? 0 );
			$g['ebit']    += (float) ( $h['ebit_usd'] ?? 0 );
			$g['nopat']   += (float) ( $h['nopat_usd'] ?? 0 );
			$g['ci']      += (float) ( $h['invested_capital_usd'] ?? 0 );
			$g['eva']     += (float) ( $h['eva_usd'] ?? 0 );
			$g['wci']     += (float) ( $h['wacc_pct'] ?? 0 ) / 100 * (float) ( $h['invested_capital_usd'] ?? 0 );
			unset( $g );
		}
		$pre = [];
		foreach ( $hist_group as $y => $g ) {
			$pre[] = [ 'label' => $y . ' real', 'year' => (int) $y, 'source' => 'historial', 'revenue' => $g['revenue'], 'ebit' => $g['ebit'], 'nopat' => $g['nopat'], 'ci' => $g['ci'],
				'roic' => $g['ci'] > 0 ? $g['nopat'] / $g['ci'] : null, 'wacc' => $g['ci'] > 0 ? $g['wci'] / $g['ci'] : null, 'eva' => $g['eva'] ];
		}
		$series['GRUPO'] = array_merge( $pre, $series['GRUPO'] ?? [] );
		return $series;
	}

	/**
	 * Sensibilidad de ROIC y EVA: tipo de cambio ±10 % (monedas locales distintas de USD) y volumen ±10 % (ventas y
	 * costo de ventas se mueven con el volumen; los gastos quedan fijos).
	 *
	 * @param array $result salida de Consolidator::run.
	 * @param array $ctx    ['corporate'=>…, 'kpi'=>salida de Kpi::compute (opcional)].
	 */
	public static function scenarios( array $result, array $ctx ): array {
		$corp  = (array) ( $ctx['corporate'] ?? [] );
		$kpi   = $ctx['kpi'] ?? Kpi::compute( $result, $corp );
		$roles = Support::roles( $corp );
		$lines = [];
		foreach ( (array) ( $result['lines'] ?? [] ) as $l ) {
			$lines[ $l['account'] ] = $l;
		}
		$gm = [];
		foreach ( (array) ( $result['companies'] ?? [] ) as $c ) {
			$s = 0.0;
			foreach ( $roles['sales'] as $acc ) {
				$s += (float) ( $lines[ $acc ]['by_company'][ $c ] ?? 0 );
			}
			$cg = 0.0;
			foreach ( $roles['cogs'] as $acc ) {
				$cg += (float) ( $lines[ $acc ]['by_company'][ $c ] ?? 0 );
			}
			$gm[ $c ] = $s - $cg;
		}
		$cases = [ 'base' => [ 1.0, 0.0 ], 'tc_mas_10' => [ 1.1, 0.0 ], 'tc_menos_10' => [ 0.9, 0.0 ], 'volumen_mas_10' => [ 1.0, 0.1 ], 'volumen_menos_10' => [ 1.0, -0.1 ] ];
		$out   = [ 'cases' => array_keys( $cases ), 'companies' => [], 'group' => [] ];
		foreach ( $cases as $name => [ $fx, $vol ] ) {
			$g = [ 'nopat' => 0.0, 'ci' => 0.0, 'wci' => 0.0 ];
			foreach ( (array) ( $kpi['companies'] ?? [] ) as $c => $k ) {
				$f     = 'USD' === ( $k['currency'] ?? 'USD' ) ? 1.0 : 1 / $fx; // tasa ×1,1 → montos en USD ÷1,1
				$ebit  = ( (float) $k['ebit'] + $vol * ( $gm[ $c ] ?? 0 ) ) * $f;
				$nopat = $ebit * ( 1 - (float) ( $k['tax'] ?? 0 ) );
				$ci    = (float) $k['ci'] * $f;
				$out['companies'][ $c ][ $name ] = [ 'ebit' => $ebit, 'nopat' => $nopat, 'ci' => $ci, 'roic' => $ci > 0 ? $nopat / $ci : null, 'eva' => $nopat - (float) $k['wacc'] * $ci ];
				$g['nopat'] += $nopat;
				$g['ci']    += $ci;
				$g['wci']   += (float) $k['wacc'] * $ci;
			}
			// CONTRATO: el escenario de grupo suma los países (sin volver a consolidar).
			$out['group'][ $name ] = [ 'nopat' => $g['nopat'], 'ci' => $g['ci'], 'roic' => $g['ci'] > 0 ? $g['nopat'] / $g['ci'] : null, 'eva' => $g['nopat'] - $g['wci'] ];
		}
		return $out;
	}
}
