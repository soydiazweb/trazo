<?php
/**
 * Desviaciones entre dos versiones (CONTRACT §11): $resA = comparada, $resB = base (salidas de Consolidator::run).
 *
 * Ventas por línea y país, mes a mes (cada mes con sus tasas): volumen = (VolA − VolB) × PrecioB / tasaB;
 * precio = (PrecioA − PrecioB) × VolA / tasaB; tipo de cambio = VentasLocalA × (1/tasaA − 1/tasaB); otros = residuo
 * exacto (líneas nuevas, diferencias entre ventas netas y cuentas). Grupo: suma de países + intercompañía (cambio
 * en las eliminaciones de ventas). Demás cuentas: a tipo de cambio constante + tipo de cambio (+ eliminaciones).
 *
 * PHP puro: sin funciones de WordPress.
 */

namespace Consolida\Engine;

final class Variance {

	public const EFFECTS = [ 'volumen', 'precio', 'tipo_cambio', 'intercompania', 'otros' ];

	/** Frases por efecto: [positivo, negativo]. */
	private const LABELS = [
		'volumen'       => [ 'más volumen', 'menos volumen' ],
		'precio'        => [ 'mejores precios', 'precios más bajos' ],
		'tipo_cambio'   => [ 'tipo de cambio a favor', 'tipo de cambio en contra' ],
		'intercompania' => [ 'menos ventas entre empresas del grupo', 'más ventas entre empresas del grupo' ],
		'otros'         => [ 'otros efectos a favor', 'otros efectos en contra' ],
	];

	/**
	 * @param array $resA salida de Consolidator::run de la versión comparada.
	 * @param array $resB salida de Consolidator::run de la versión base.
	 * @param array $ctx  ['corporate'=>Corporate (para el catálogo y las líneas), 'company'=>código (opcional)].
	 */
	public static function compare( array $resA, array $resB, array $ctx = [] ): array {
		$corp  = (array) ( $ctx['corporate'] ?? [] );
		$roles = Support::roles( $corp );
		$codes = array_values( array_unique( array_merge( (array) ( $resA['companies'] ?? [] ), (array) ( $resB['companies'] ?? [] ) ) ) );
		$lines = Support::lines( $corp );
		foreach ( [ $resA, $resB ] as $res ) {
			foreach ( (array) ( $res['sales_stats'] ?? [] ) as $by_line ) {
				foreach ( array_keys( $by_line ) as $l ) {
					if ( ! in_array( $l, $lines, true ) ) {
						$lines[] = (string) $l;
					}
				}
			}
		}
		$la = self::index( $resA );
		$lb = self::index( $resB );

		// Ventas por país y línea.
		$companies = [];
		$group     = [ 'lines' => [] ];
		foreach ( $codes as $c ) {
			$tot = self::blank();
			$by  = [];
			foreach ( $lines as $line ) {
				$x = self::line_effects( $resA['sales_stats'][ $c ][ $line ] ?? [], $resB['sales_stats'][ $c ][ $line ] ?? [], $resA['rates'][ $c ] ?? null, $resB['rates'][ $c ] ?? null );
				if ( 0.0 === $x['a'] && 0.0 === $x['b'] ) {
					continue;
				}
				$x['text']    = self::text( 'de ' . $line . ' en ' . $c, $x['b'], $x['delta'], $x['effects'] );
				$by[ $line ]  = $x;
				$tot          = self::add( $tot, $x );
				$group['lines'][ $line ] = self::add( $group['lines'][ $line ] ?? self::blank(), $x );
			}
			$tot['text']        = self::text( 'de ' . $c, $tot['b'], $tot['delta'], $tot['effects'] );
			$tot['lines']       = $by;
			$companies[ $c ]    = $tot;
		}
		// Grupo: + cambio en eliminaciones de ventas intercompañía por línea.
		$gt = self::blank();
		foreach ( $group['lines'] as $line => &$g ) {
			$acc = $roles['sales_ic'][ $line ] ?? null;
			$ic  = null === $acc ? 0.0 : (float) ( $la[ $acc ]['eliminations'] ?? 0 ) - (float) ( $lb[ $acc ]['eliminations'] ?? 0 );
			$g['a']                       += null === $acc ? 0.0 : (float) ( $la[ $acc ]['eliminations'] ?? 0 );
			$g['b']                       += null === $acc ? 0.0 : (float) ( $lb[ $acc ]['eliminations'] ?? 0 );
			$g['delta']                    = $g['a'] - $g['b'];
			$g['effects']['intercompania'] = $ic;
			$g['effects']['otros']         = $g['delta'] - $g['effects']['volumen'] - $g['effects']['precio'] - $g['effects']['tipo_cambio'] - $ic;
			$g['text']                     = self::text( 'de ' . $line . ' del grupo', $g['b'], $g['delta'], $g['effects'] );
			$gt                            = self::add( $gt, $g );
		}
		unset( $g );
		$gt['account'] = implode( ',', $roles['sales'] );
		$gt['text']    = self::text( 'del grupo', $gt['b'], $gt['delta'], $gt['effects'] );
		$gt['lines']   = $group['lines'];

		$out = [
			'version_a' => $resA['version'] ?? null,
			'version_b' => $resB['version'] ?? null,
			'group'     => $gt,
			'companies' => $companies,
			'accounts'  => self::accounts( $resA, $resB, $la, $lb, $codes ),
		];
		if ( ! empty( $ctx['company'] ) ) {
			$only             = (string) $ctx['company'];
			$out['companies'] = isset( $companies[ $only ] ) ? [ $only => $companies[ $only ] ] : [];
		}
		return $out;
	}

	/** Efectos de una línea de un país, sumando mes a mes. */
	private static function line_effects( array $ma, array $mb, ?array $ra, ?array $rb ): array {
		$x = self::blank();
		for ( $m = 1; $m <= 12; $m++ ) {
			$a  = $ma[ $m ] ?? null;
			$b  = $mb[ $m ] ?? null;
			$sa = (float) ( $a['sales_local'] ?? 0 );
			$sb = (float) ( $b['sales_local'] ?? 0 );
			$rA = (float) ( $a['rate'] ?? ( $ra['avg'][ $m ] ?? 0 ) );
			$rB = (float) ( $b['rate'] ?? ( $rb['avg'][ $m ] ?? 0 ) );
			$ua = $rA > 0 ? $sa / $rA : 0.0;
			$ub = $rB > 0 ? $sb / $rB : 0.0;
			$vol = $price = $fx = 0.0;
			if ( $rB > 0 ) {
				$va = (float) ( $a['volume'] ?? 0 );
				$vb = (float) ( $b['volume'] ?? 0 );
				$na = (float) ( $a['net_local'] ?? 0 );
				$nb = (float) ( $b['net_local'] ?? 0 );
				if ( $vb > 0 ) {
					$pb = $nb / $vb;
					if ( $va > 0 ) {
						$pa    = $na / $va;
						$vol   = ( $va - $vb ) * $pb / $rB;
						$price = ( $pa - $pb ) * $va / $rB;
					} else {
						$vol = -$nb / $rB;
					}
				}
				if ( $rA > 0 ) {
					$fx = $sa * ( 1 / $rA - 1 / $rB );
				}
			}
			$d = $ua - $ub;
			$x['a']     += $ua;
			$x['b']     += $ub;
			$x['delta'] += $d;
			$x['effects']['volumen']     += $vol;
			$x['effects']['precio']      += $price;
			$x['effects']['tipo_cambio'] += $fx;
			$x['effects']['otros']       += $d - $vol - $price - $fx;
			$x['months'][ $m ] = [ 'a' => $ua, 'b' => $ub, 'delta' => $d, 'volume_a' => $a['volume'] ?? null, 'volume_b' => $b['volume'] ?? null, 'volumen' => $vol, 'precio' => $price, 'tipo_cambio' => $fx ];
		}
		return $x;
	}

	/** Demás cuentas: a tipo de cambio constante + tipo de cambio por país, + eliminaciones en el grupo. */
	private static function accounts( array $resA, array $resB, array $la, array $lb, array $codes ): array {
		$out  = [];
		$accs = array_unique( array_merge( array_keys( $la ), array_keys( $lb ) ) );
		foreach ( $accs as $acc ) {
			$x     = $la[ $acc ] ?? $lb[ $acc ];
			$is_pl = 'PL' === ( $x['statement'] ?? 'PL' );
			$row   = [
				'account' => (string) $acc, 'name' => $x['name'] ?? '', 'statement' => $is_pl ? 'PL' : 'BS',
				'a' => (float) ( $la[ $acc ]['total'] ?? 0 ), 'b' => (float) ( $lb[ $acc ]['total'] ?? 0 ), 'delta' => 0.0,
				'a_tipo_constante' => 0.0, 'tipo_cambio' => 0.0,
				'eliminaciones' => (float) ( $la[ $acc ]['eliminations'] ?? 0 ) - (float) ( $lb[ $acc ]['eliminations'] ?? 0 ),
				'otros' => 0.0, 'by_company' => [],
			];
			$row['delta'] = $row['a'] - $row['b'];
			foreach ( $codes as $c ) {
				$months = $is_pl ? range( 1, 12 ) : [ 12 ];
				$cc     = [ 'a' => 0.0, 'b' => 0.0, 'delta' => 0.0, 'a_tipo_constante' => 0.0, 'tipo_cambio' => 0.0, 'otros' => 0.0, 'months' => [] ];
				foreach ( $months as $m ) {
					$lA = (float) ( $la[ $acc ]['local_months'][ $c ][ $m ] ?? 0 );
					$lB = (float) ( $lb[ $acc ]['local_months'][ $c ][ $m ] ?? 0 );
					$uA = (float) ( $la[ $acc ]['by_company_months'][ $c ][ $m ] ?? 0 );
					$uB = (float) ( $lb[ $acc ]['by_company_months'][ $c ][ $m ] ?? 0 );
					$kind = $is_pl ? 'avg' : 'close';
					$rA   = (float) ( $resA['rates'][ $c ][ $kind ][ $m ] ?? 0 );
					$rB   = (float) ( $resB['rates'][ $c ][ $kind ][ $m ] ?? 0 );
					$k    = $rB > 0 ? ( $lA - $lB ) / $rB : 0.0;
					$f    = ( $rA > 0 && $rB > 0 ) ? $lA * ( 1 / $rA - 1 / $rB ) : 0.0;
					$cc['a']                += $uA;
					$cc['b']                += $uB;
					$cc['a_tipo_constante'] += $k;
					$cc['tipo_cambio']      += $f;
					$cc['months'][ $m ]      = [ 'a' => $uA, 'b' => $uB, 'delta' => $uA - $uB, 'local_a' => $lA, 'local_b' => $lB ];
				}
				$cc['delta'] = $cc['a'] - $cc['b'];
				$cc['otros'] = $cc['delta'] - $cc['a_tipo_constante'] - $cc['tipo_cambio'];
				if ( 0.0 === $cc['a'] && 0.0 === $cc['b'] ) {
					continue;
				}
				$row['a_tipo_constante'] += $cc['a_tipo_constante'];
				$row['tipo_cambio']      += $cc['tipo_cambio'];
				$row['by_company'][ $c ]  = $cc;
			}
			$row['otros'] = $row['delta'] - $row['a_tipo_constante'] - $row['tipo_cambio'] - $row['eliminaciones'];
			$out[]        = $row;
		}
		return $out;
	}

	/**
	 * Texto en español sencillo con los dos efectos mayores:
	 * «Las ventas de E3 bajan 8 %: 6 puntos por menos volumen y 2 por tipo de cambio en contra.»
	 */
	public static function text( string $who, float $base, float $delta, array $effects ): string {
		if ( abs( $base ) < 0.005 ) {
			return abs( $delta ) < 0.005
				? sprintf( 'Las ventas %s no tienen movimiento.', $who )
				: sprintf( 'Las ventas %s pasan de 0 a %s USD.', $who, Support::fmt( $delta, 0 ) );
		}
		$pct  = $delta / abs( $base ) * 100;
		$head = abs( $pct ) < 0.05
			? sprintf( 'Las ventas %s se mantienen', $who )
			: sprintf( 'Las ventas %s %s %s %%', $who, $pct > 0 ? 'suben' : 'bajan', self::num1( abs( $pct ) ) );
		$list = [];
		foreach ( $effects as $k => $v ) {
			if ( isset( self::LABELS[ $k ] ) && abs( $v ) >= 0.005 && abs( $v ) / abs( $base ) * 100 >= 0.05 ) {
				$list[ $k ] = $v;
			}
		}
		uasort( $list, fn( $x, $y ) => abs( $y ) <=> abs( $x ) );
		$parts = [];
		foreach ( array_slice( $list, 0, 2, true ) as $k => $v ) {
			$pts     = self::num1( abs( $v ) / abs( $base ) * 100 );
			$parts[] = ( $parts ? $pts : $pts . ' ' . ( '1' === $pts ? 'punto' : 'puntos' ) ) . ' por ' . self::LABELS[ $k ][ $v >= 0 ? 0 : 1 ];
		}
		return $head . ( $parts ? ': ' . implode( ' y ', $parts ) : '' ) . '.';
	}

	/* ------------------------------------------------------------------ apoyo */

	private static function blank(): array {
		return [ 'a' => 0.0, 'b' => 0.0, 'delta' => 0.0, 'effects' => array_fill_keys( self::EFFECTS, 0.0 ), 'months' => [] ];
	}

	private static function add( array $t, array $x ): array {
		$t['a']     += $x['a'];
		$t['b']     += $x['b'];
		$t['delta'] += $x['delta'];
		foreach ( self::EFFECTS as $e ) {
			$t['effects'][ $e ] += $x['effects'][ $e ] ?? 0.0;
		}
		return $t;
	}

	private static function index( array $res ): array {
		$out = [];
		foreach ( (array) ( $res['lines'] ?? [] ) as $l ) {
			$out[ (string) $l['account'] ] = $l;
		}
		return $out;
	}

	/** 1 decimal sin «.0». */
	private static function num1( float $v ): string {
		$r = round( $v, 1 );
		return Support::fmt( $r, abs( $r - round( $r ) ) < 1e-9 ? 0 : 1 );
	}
}
