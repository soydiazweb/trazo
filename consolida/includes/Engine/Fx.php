<?php
/**
 * Conversión mensual (CONTRACT §9). Tasas = moneda local por 1 USD, de `Corporate['rates'][versión][moneda][mes]`.
 * Resultados del mes a `avg` del mes; balance del mes a `close` del mes; saldo inicial (mes 0) a la tasa de saldo
 * inicial (§4). USD = 1.
 *
 * PHP puro: sin funciones de WordPress.
 */

namespace Consolida\Engine;

final class Fx {


	public static function to_usd( float $local, float $rate ): float {
		if ( $rate <= 0.0 ) {
			throw new \InvalidArgumentException( 'La tasa de cambio debe ser mayor que cero.' );
		}
		return $local / $rate;
	}

	/**
	 * Tasa de una moneda, versión y mes. $kind = 'avg' | 'close' | 'budget'. Mes 0 = tasa de saldo inicial.
	 *
	 * @throws \InvalidArgumentException si falta la tasa.
	 */
	public static function rate( array $corporate, string $version, string $currency, int $month, string $kind ): float {
		$currency = strtoupper( trim( $currency ) );
		if ( 'USD' === $currency || '' === $currency ) {
			return 1.0;
		}
		if ( 0 === $month ) {
			return self::opening( $corporate, $version, $currency );
		}
		$r = $corporate['rates'][ $version ][ $currency ][ $month ][ $kind ] ?? null;
		if ( ! is_numeric( $r ) || (float) $r <= 0 ) {
			throw new \InvalidArgumentException( sprintf( 'Falta la tasa %s de %s para %s, mes %d.', self::kind_name( $kind ), $currency, $version, $month ) );
		}
		return (float) $r;
	}

	/** Como rate() pero devuelve null si falta. */
	public static function try_rate( array $corporate, string $version, string $currency, int $month, string $kind ): ?float {
		try {
			return self::rate( $corporate, $version, $currency, $month, $kind );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Tasa de saldo inicial (§4): rates[versión][moneda][0].close; si no, cierre de diciembre de real_<año−1>; si no,
	 * cierre del mes 1.
	 */
	public static function opening( array $corporate, string $version, string $currency ): float {
		$currency = strtoupper( $currency );
		if ( 'USD' === $currency ) {
			return 1.0;
		}
		$rates = (array) ( $corporate['rates'] ?? [] );
		$r     = $rates[ $version ][ $currency ][0]['close'] ?? null;
		if ( ! is_numeric( $r ) ) {
			$year = Support::version_year( $version );
			$r    = null === $year ? null : ( $rates[ 'real_' . ( $year - 1 ) ][ $currency ][12]['close'] ?? null );
		}
		if ( ! is_numeric( $r ) ) {
			$r = $rates[ $version ][ $currency ][1]['close'] ?? null;
		}
		if ( ! is_numeric( $r ) || (float) $r <= 0 ) {
			throw new \InvalidArgumentException( sprintf( 'Falta la tasa de saldo inicial de %s para %s.', $currency, $version ) );
		}
		return (float) $r;
	}

	/** Promedio simple de las tasas promedio mensuales del año (la «tasa oficial promedio anual»). */
	public static function annual_avg( array $corporate, string $version, string $currency ): ?float {
		if ( 'USD' === strtoupper( $currency ) ) {
			return 1.0;
		}
		$sum = 0.0;
		$n   = 0;
		for ( $m = 1; $m <= 12; $m++ ) {
			$r = self::try_rate( $corporate, $version, $currency, $m, 'avg' );
			if ( null !== $r ) {
				$sum += $r;
				$n++;
			}
		}
		return $n ? $sum / $n : null;
	}

	/** Tasa para un concepto: PL del mes a avg; BS del mes a close; mes 0 a la de saldo inicial. */
	public static function rate_for( array $corporate, string $version, string $currency, int $month, bool $is_pl ): float {
		return self::rate( $corporate, $version, $currency, $month, $is_pl ? 'avg' : 'close' );
	}

	/** Convierte un monto local del mes. */
	public static function convert( float $local, array $corporate, string $version, string $currency, int $month, bool $is_pl ): float {
		return self::to_usd( $local, self::rate_for( $corporate, $version, $currency, $month, $is_pl ) );
	}

	/** Vista a tipo de cambio constante: convierte con las tasas de otra versión. */
	public static function constant( float $local, array $corporate, string $other_version, string $currency, int $month, bool $is_pl ): float {
		return self::convert( $local, $corporate, $other_version, $currency, $month, $is_pl );
	}

	/**
	 * Línea intercompañía (moneda pactada) a USD: flujos a avg del mes.
	 * CONTRATO: §9 dice «avg (flujos) o close (saldos)»; todas las líneas de la hoja intercompania son flujos del mes
	 * (ventas, cuotas, intereses y desembolsos de préstamo), así que se usa avg.
	 */
	public static function ic_to_usd( array $line, array $corporate, string $version ): float {
		$m = max( 1, (int) ( $line['month'] ?? 1 ) );
		return self::to_usd( (float) ( $line['amount'] ?? 0 ), self::rate( $corporate, $version, (string) ( $line['currency'] ?? 'USD' ), $m, 'avg' ) );
	}

	/** De USD a la moneda local del mes (avg). */
	public static function from_usd( float $usd, array $corporate, string $version, string $currency, int $month ): float {
		return $usd * self::rate( $corporate, $version, $currency, max( 1, $month ), 'avg' );
	}

	private static function kind_name( string $kind ): string {
		return [ 'avg' => 'promedio', 'close' => 'de cierre', 'budget' => 'de presupuesto' ][ $kind ] ?? $kind;
	}
}
