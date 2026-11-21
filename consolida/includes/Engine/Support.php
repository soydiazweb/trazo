<?php
/**
 * Utilidades compartidas del motor financiero v2: lectura del catálogo del corporativo (§4), roles de cuentas
 * derivados de sus atributos, formato es-GT e Issues (§7).
 *
 * Nada fijo a países ni monedas: todo sale del Corporate y de los Datasets.
 * PHP puro: sin funciones de WordPress.
 */

namespace Consolida\Engine;

final class Support {

	public const CRITICAL = 'critico';
	public const WARNING  = 'aviso';

	/** Las 6 categorías de historial_ciclos. */
	public const CATEGORIES = [
		'formato_escala'   => 'Formato y escala',
		'catalogo_periodo' => 'Catálogo y periodo',
		'calculos_totales' => 'Cálculos y totales',
		'intercompania'    => 'Intercompañía',
		'tipo_cambio'      => 'Tipo de cambio',
		'indicadores'      => 'Indicadores copiados',
	];

	/** Etiquetas de versión (§1). */
	public const VERSION_LABELS = [
		'ppto_2026'    => 'Presupuesto 2026',
		'real_2026'    => 'Real 2026',
		'ppto_2027_v1' => 'Presupuesto 2027 v1',
		'ppto_2027_v2' => 'Presupuesto 2027 v2',
	];

	/** Nombres de mes para mensajes. */
	public const MONTHS = [ 0 => 'saldo inicial', 1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic' ];

	/* ------------------------------------------------------------------ Issues */

	/**
	 * Issue §7 con todas las claves. $f trae las que se conozcan.
	 */
	public static function issue( string $code, string $severity, string $category, array $f, string $message ): array {
		return [
			'code'       => $code,
			'severity'   => $severity,
			'category'   => $category,
			'company'    => (string) ( $f['company'] ?? '' ),
			'version'    => (string) ( $f['version'] ?? '' ),
			'sheet'      => $f['sheet'] ?? null,
			'row'        => isset( $f['row'] ) ? (int) $f['row'] : null,
			'col'        => $f['col'] ?? null,
			'month'      => isset( $f['month'] ) ? (int) $f['month'] : null,
			'concept'    => isset( $f['concept'] ) ? (string) $f['concept'] : null,
			'message'    => $message,
			'actual'     => isset( $f['actual'] ) ? (float) $f['actual'] : null,
			'expected'   => isset( $f['expected'] ) ? (float) $f['expected'] : null,
			'impact_usd' => isset( $f['impact_usd'] ) ? (float) $f['impact_usd'] : null,
		];
	}

	/** Campos de ubicación de un `src` (§6) para un Issue. */
	public static function at( ?array $src ): array {
		return [ 'sheet' => $src['sheet'] ?? null, 'row' => $src['row'] ?? null, 'col' => $src['col'] ?? null ];
	}

	/** Clave para no repetir Issues (mismo código y ubicación). */
	public static function issue_key( array $i ): string {
		return implode( '|', [ $i['code'] ?? '', $i['company'] ?? '', $i['version'] ?? '', $i['sheet'] ?? '', $i['row'] ?? '', $i['col'] ?? '', $i['month'] ?? '', $i['concept'] ?? '' ] );
	}

	/** Cuenta críticos y avisos. */
	public static function count( array $issues ): array {
		$out = [ self::CRITICAL => 0, self::WARNING => 0 ];
		foreach ( $issues as $i ) {
			$s         = $i['severity'] ?? self::WARNING;
			$out[ $s ] = ( $out[ $s ] ?? 0 ) + 1;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ catálogo */

	/** ¿El concepto es una cuenta del catálogo? */
	public static function is_account( string $concept, array $catalog ): bool {
		return isset( $catalog[ $concept ] );
	}

	/** Valor sí/no del corporativo como booleano (true, 1, «si», «sí», «yes»). */
	public static function yes( mixed $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_numeric( $v ) ) {
			return (float) $v > 0;
		}
		return in_array( self::plain( (string) $v ), [ 'si', 'yes', 'y', 's', 'true', 'x' ], true );
	}

	/** Signo del catálogo (+1 / −1). */
	public static function sign( array $acc ): int {
		$s = $acc['sign'] ?? 1;
		return ( '-' === $s || '−' === $s || (int) $s < 0 ) ? -1 : 1;
	}

	/** Líneas de producto del maestro (únicas, en orden). */
	public static function lines( array $corporate ): array {
		$out = [];
		foreach ( (array) ( $corporate['products'] ?? [] ) as $p ) {
			$l = (string) ( $p['line'] ?? '' );
			if ( '' !== $l && ! in_array( $l, $out, true ) ) {
				$out[] = $l;
			}
		}
		return $out;
	}

	/** Línea de producto que menciona el nombre de una cuenta (sin tildes ni mayúsculas) o null. */
	public static function line_of( array $acc, array $lines ): ?string {
		$name = self::plain( (string) ( $acc['name'] ?? '' ) );
		foreach ( $lines as $l ) {
			if ( '' !== $l && str_contains( $name, self::plain( $l ) ) ) {
				return $l;
			}
		}
		return null;
	}

	/**
	 * Roles de cuentas derivados de los atributos del catálogo (tipo, subtipo, operativa, intercompañía):
	 * - sales_third[línea], sales_ic[línea], cogs[línea]  (ventas a terceros / intercompañía / costo por línea)
	 * - mgmt_income, mgmt_expense, int_income, int_expense (cuota y los intereses intercompañía)
	 * - ic_receivable, ic_payable, ic_inventory, loan_receivable, loan_payable (saldos intercompañía)
	 * - margin_elim (5901), minority (3102), equity (patrimonio del país)
	 * - wc_assets[], wc_liabilities[], fixed[], current_liabilities[], assets[], liabilities_equity[]
	 */
	public static function roles( array $corporate ): array {
		$catalog = (array) ( $corporate['catalog'] ?? [] );
		$lines   = self::lines( $corporate );
		$r       = [
			'sales_third' => [], 'sales_ic' => [], 'cogs' => [], 'mgmt_income' => null, 'mgmt_expense' => null,
			'int_income' => null, 'int_expense' => null, 'ic_receivable' => null, 'ic_payable' => null,
			'ic_inventory' => null, 'loan_receivable' => null, 'loan_payable' => null, 'margin_elim' => null,
			'minority' => null, 'equity' => null, 'wc_assets' => [], 'wc_liabilities' => [], 'fixed' => [],
			'current_liabilities' => [], 'assets' => [], 'liabilities_equity' => [], 'sales' => [],
		];
		foreach ( $catalog as $code => $a ) {
			$code = (string) $code;
			$t    = (string) ( $a['type'] ?? '' );
			$st   = (string) ( $a['subtype'] ?? '' );
			$ic   = self::yes( $a['intercompany'] ?? false );
			$name = self::plain( (string) ( $a['name'] ?? '' ) );
			$line = self::line_of( $a, $lines );
			$only = ! empty( $a['consolidated_only'] ) || str_contains( $name, 'solo en el consolidado' ) || str_contains( $name, 'minoritario' ) || str_contains( $name, 'eliminacion' );
			if ( 'ingreso' === $t && 'ventas' === $st ) {
				$r['sales'][] = $code;
				if ( $line ) {
					$r[ $ic ? 'sales_ic' : 'sales_third' ][ $line ] = $code;
				}
			} elseif ( 'costo' === $t && 'costo_ventas' === $st ) {
				if ( $ic || $only ) {
					$r['margin_elim'] ??= $code;
				} elseif ( $line ) {
					$r['cogs'][ $line ] = $code;
				}
			} elseif ( $ic && 'ingreso' === $t && 'financiero' !== $st ) {
				$r['mgmt_income'] ??= $code;
			} elseif ( $ic && 'gasto' === $t && 'financiero' !== $st ) {
				$r['mgmt_expense'] ??= $code;
			} elseif ( $ic && 'ingreso' === $t ) {
				$r['int_income'] ??= $code;
			} elseif ( $ic && 'gasto' === $t ) {
				$r['int_expense'] ??= $code;
			}
			if ( 'activo' === $t ) {
				$r['assets'][] = $code;
				if ( 'capital_trabajo' === $st ) {
					$r['wc_assets'][] = $code;
					if ( $ic ) {
						if ( str_contains( $name, 'inventario' ) || str_contains( $name, 'inventory' ) ) {
							$r['ic_inventory'] ??= $code;
						} else {
							$r['ic_receivable'] ??= $code;
						}
					}
				} elseif ( 'activo_fijo' === $st ) {
					$r['fixed'][] = $code;
				} elseif ( $ic ) {
					$r['loan_receivable'] ??= $code;
				}
			} elseif ( 'pasivo' === $t ) {
				$r['liabilities_equity'][] = $code;
				if ( 'capital_trabajo' === $st ) {
					$r['wc_liabilities'][] = $code;
					if ( $ic ) {
						$r['ic_payable'] ??= $code;
					}
				} elseif ( $ic ) {
					$r['loan_payable'] ??= $code;
				}
				if ( self::yes( $a['current'] ?? false ) ) {
					$r['current_liabilities'][] = $code;
				}
			} elseif ( 'patrimonio' === $t ) {
				$r['liabilities_equity'][] = $code;
				if ( $only ) {
					$r['minority'] ??= $code;
				} else {
					$r['equity'] ??= $code;
				}
			}
		}
		return $r;
	}

	/**
	 * Efecto de una cuenta en el EBIT (+1 ingreso operativo, −1 costo/gasto operativo, 0 si no es operativa) y en la
	 * utilidad neta (+1 ingreso, −1 costo/gasto).
	 *
	 * @return array{ebit:int,net:int}
	 */
	public static function pl_effect( array $acc ): array {
		$t   = (string) ( $acc['type'] ?? '' );
		$net = 'ingreso' === $t ? 1 : ( in_array( $t, [ 'costo', 'gasto' ], true ) ? -1 : 0 );
		$op  = self::yes( $acc['operating'] ?? false );
		return [ 'ebit' => $op ? $net : 0, 'net' => $net ];
	}

	/** ¿Cuenta de resultados? */
	public static function is_pl( array $acc ): bool {
		return in_array( $acc['type'] ?? '', [ 'ingreso', 'costo', 'gasto' ], true );
	}

	/* ------------------------------------------------------------------ Dataset */

	/**
	 * Indexa filas del Dataset (pl o bs) por concepto y mes: [concepto][mes] = ['value'=>float|null,'src'=>…,'count'=>n].
	 * Si un concepto se repite en el mes, se suman los valores (DATA_DUP lo reporta aparte).
	 */
	public static function grid( array $rows ): array {
		$out = [];
		foreach ( $rows as $r ) {
			$c = (string) ( $r['concept'] ?? '' );
			$m = (int) ( $r['month'] ?? 0 );
			if ( '' === $c ) {
				continue;
			}
			$v = is_numeric( $r['value'] ?? null ) ? (float) $r['value'] : null;
			if ( ! isset( $out[ $c ][ $m ] ) ) {
				$out[ $c ][ $m ] = [ 'value' => $v, 'src' => $r['src'] ?? null, 'count' => 1, 'label' => $r['label'] ?? null ];
				continue;
			}
			$cell = &$out[ $c ][ $m ];
			$cell['count']++;
			if ( null !== $v ) {
				$cell['value'] = ( $cell['value'] ?? 0.0 ) + $v;
			}
			unset( $cell );
		}
		return $out;
	}

	/** Valor numérico de una celda del grid (0 si falta). */
	public static function val( array $grid, string $concept, int $month ): float {
		return (float) ( $grid[ $concept ][ $month ]['value'] ?? 0.0 );
	}

	/** Moneda de una empresa: la del corporativo o la del archivo. */
	public static function currency( array $corporate, string $company, array $ds = [] ): string {
		return strtoupper( (string) ( $corporate['companies'][ $company ]['currency'] ?? ( $ds['meta']['currency'] ?? 'USD' ) ) );
	}

	/* ------------------------------------------------------------------ versiones */

	/** Año de una clave de versión: «ppto_2027_v1» → 2027. */
	public static function version_year( string $version ): ?int {
		return preg_match( '/(20\d{2})/', $version, $m ) ? (int) $m[1] : null;
	}

	public static function version_label( string $version ): string {
		return self::VERSION_LABELS[ $version ] ?? $version;
	}

	/* ------------------------------------------------------------------ formato */

	/** Número con formato es-GT (1,234,567.89). Sin decimales si es entero (tras redondear a centavos). */
	public static function fmt( ?float $value, ?int $decimals = null ): string {
		if ( null === $value ) {
			return '—';
		}
		if ( null === $decimals ) {
			$decimals = abs( $value - round( $value ) ) < 0.005 ? 0 : 2;
		}
		$s = number_format( $value, $decimals, '.', ',' );
		return preg_match( '/^-[0.,]+$/', $s ) ? substr( $s, 1 ) : $s;
	}

	/** Fracción como porcentaje: 0.1184 → «11.8 %». */
	public static function pct( ?float $fraction, int $decimals = 1 ): string {
		return null === $fraction ? '—' : self::fmt( $fraction * 100, $decimals ) . ' %';
	}

	/** Monto con moneda: «1,234,567 GTQ». */
	public static function money( ?float $v, string $currency, int $decimals = 0 ): string {
		return self::fmt( $v, $decimals ) . ' ' . $currency;
	}

	public static function month_name( ?int $m ): string {
		return null === $m ? '' : ( self::MONTHS[ $m ] ?? (string) $m );
	}

	/** Texto sin tildes y en minúsculas para comparar. */
	public static function plain( string $s ): string {
		$s = mb_strtolower( trim( $s ) );
		return strtr( $s, [ 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' ] );
	}

	/** División segura. */
	public static function div( float $a, float $b ): ?float {
		return 0.0 === $b ? null : $a / $b;
	}
}
