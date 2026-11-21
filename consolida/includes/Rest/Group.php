<?php
namespace Consolida\Rest;

use Consolida\EngineBridge;
use Consolida\EngineMissing;
use Consolida\Log;
use Consolida\Roles;
use Consolida\Store;

/**
 * Vista del grupo: intercompañía, consolidación, resultados, trazabilidad, desviaciones, indicadores, resumen
 * ejecutivo, hallazgos, propuestas, historial (preventivo y evolutivo), calidad y acciones.
 * Todas las llamadas al motor pasan por EngineBridge.
 */
class Group extends Base {
	/** Operaciones opcionales que faltaron en la petición actual (se informan en la respuesta). */
	private static array $unavailable = array();

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		self::route( '/ic', 'GET', 'ic', 'corp' );
		self::route( '/consolidate', 'POST', 'consolidate', 'corp' );
		self::route( '/results/latest', 'GET', 'result_latest', 'corp_board' );
		self::route( '/results/(?P<id>\d+)', 'GET', 'result_get', 'corp_board' );
		self::route( '/trace', 'GET', 'trace', 'corp_board' );
		self::route( '/variance', 'GET', 'variance', 'corp_board' );
		self::route( '/kpi', 'GET', 'kpi', 'corp_board' );
		self::route( '/summary', 'GET', 'summary', 'board' );
		self::route( '/findings', 'GET', 'findings' );
		self::route( '/proposals', 'GET', 'proposals', 'corp_board' );
		self::route( '/history', 'GET', 'history', 'corp_board' );
		self::route( '/quality', 'GET', 'quality', 'corp_board' );
		self::route( '/actions', 'GET', 'actions', 'board' );
		self::route( '/actions', 'POST', 'action_save', 'board' );
		self::route( '/actions/(?P<id>\d+)', 'POST', 'action_save', 'board' );
	}

	/** Llamada opcional al motor: si falta o falla devuelve null y lo anota (la respuesta sigue). */
	private static function soft( string $op, callable $fn ) {
		try {
			return $fn();
		} catch ( EngineMissing $e ) {
			self::$unavailable[ $op ] = $e->getMessage();
		} catch ( \Throwable $e ) {
			error_log( '[consolida] ' . $op . ': ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() ); // phpcs:ignore
			self::$unavailable[ $op ] = 'No se pudo calcular (' . $op . ').';
		}
		return null;
	}

	private static function codes(): array {
		return array_map( fn( $c ) => $c['code'], Store::companies() );
	}

	/* =====================================================================
	 * Intercompañía
	 * ===================================================================*/

	/** GET /ic?version= con las subidas enviadas; `all=1` (extra) usa todas las vigentes. */
	public static function ic( \WP_REST_Request $r ) {
		$v = self::version_param( $r );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		[ $datasets ] = Store::datasets( $v['key'], self::bool( $r, 'all' ) ? null : array( 'enviado' ) );
		$known        = array_values( array_filter( Store::version_issues( $v['key'], false ), fn( $i ) => null !== $i['upload_id'] ) );
		$out          = self::engine( fn() => EngineBridge::reconcile( $datasets, Store::corporate(), $v['key'], $known ), 'No se pudo conciliar la intercompañía.' );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$out['included'] = array_keys( $datasets );
		$out['missing']  = array_values( array_diff( self::codes(), $out['included'] ) );
		$out['version']  = $v;
		return $out;
	}

	/* =====================================================================
	 * Consolidación
	 * ===================================================================*/

	public static function consolidate( \WP_REST_Request $r ) {
		return self::run_consolidation( sanitize_key( (string) $r->get_param( 'version' ) ), self::bool( $r, 'force' ), get_current_user_id() );
	}

	/**
	 * Consolida una versión con las subidas `enviado` (API y demo). Exige que todas las empresas registradas hayan
	 * enviado (o force) y tasas aprobadas de los 12 meses para sus monedas.
	 *
	 * @return array|\WP_Error {result_id, result}
	 */
	public static function run_consolidation( string $key, bool $force, int $uid ) {
		$v = Store::version( $key );
		if ( ! $v ) {
			return self::not_found( 'La versión' );
		}
		$all = Store::companies();
		if ( ! $all ) {
			return self::err( 'No hay empresas: importa primero el archivo corporativo.', 409 );
		}
		$current = Store::current_uploads( $key );
		$sent    = array();
		$missing = array();
		foreach ( $all as $c ) {
			if ( 'enviado' === ( $current[ $c['id'] ]['status'] ?? '' ) ) {
				$sent[] = $c;
			} else {
				$missing[] = $c['code'];
			}
		}
		// Consolidación provisional (force): incluye también las subidas vigentes que aún no se envían (con críticos
		// abiertos), para ver su efecto en el número del grupo. Queda marcada como provisional en el resultado.
		$provisional = array();
		if ( $force ) {
			foreach ( $all as $c ) {
				$u = $current[ $c['id'] ] ?? null;
				if ( $u && 'enviado' !== $u['status'] ) {
					$provisional[] = $c['code'];
					$sent[]        = $c;
				}
			}
			$missing = array_values( array_diff( $missing, $provisional ) );
		}
		if ( ! $sent ) {
			return self::err( 'Ningún país ha subido su archivo en ' . $v['label'] . '.', 409 );
		}
		if ( $missing && ! $force ) {
			return self::err( 'Faltan por enviar: ' . implode( ', ', $missing ) . '. Puedes hacer una consolidación provisional marcando «forzar».', 409, 'csl_missing' );
		}
		$no_rates = Store::missing_rates( $key, $sent );
		if ( $no_rates ) {
			return self::err( 'Faltan tasas aprobadas de los 12 meses para: ' . implode( ', ', $no_rates ) . '.', 409, 'csl_rates' );
		}
		[ $datasets, $uploads ] = Store::datasets( $key, $force ? null : array( 'enviado' ) );
		$corp                   = Store::corporate();
		$open                   = Store::version_issues( $key, true );

		$run = self::engine(
			function () use ( $datasets, $corp, $key, $open ) {
				$known  = array_values( array_filter( Store::version_issues( $key, false ), fn( $i ) => null !== $i['upload_id'] ) );
				$ic     = EngineBridge::reconcile( $datasets, $corp, $key, $known );
				$result = EngineBridge::consolidate( $datasets, $corp, $key, $ic );
				$ic_iss = Data::normalize_issues( (array) ( $ic['issues'] ?? array() ), '', $key );
				$base   = array_filter( $open, fn( $i ) => null !== $i['upload_id'] );
				// Semáforo: hallazgos abiertos de las subidas vigentes + los intercompañía recién calculados.
				$kpi = EngineBridge::kpi( $result, $corp, array_merge( $base, $ic_iss ) );
				// Indicadores copiados (KPI_COPY_*): se comparan con los KPI calculados de cada país y se guardan como
				// hallazgos de la versión junto con los intercompañía (upload_id NULL).
				$copy = array();
				foreach ( $datasets as $code => $ds ) {
					$kc = (array) ( $kpi['companies'][ $code ] ?? array() );
					try {
						$c    = EngineBridge::kpi_copied( $ds, $kc, array( 'corporate' => $corp, 'version' => $key ) );
						$copy = array_merge( $copy, Data::normalize_issues( (array) ( $c['issues'] ?? array() ), (string) $code, $key ) );
					} catch ( \Throwable $e ) {
						continue; // Sin la comparación de copiados la consolidación sigue.
					}
				}
				if ( $copy ) {
					$ic_iss = array_merge( $ic_iss, $copy );
					$kpi    = EngineBridge::kpi( $result, $corp, array_merge( $base, $ic_iss ) );
				}
				return array( $ic, $ic_iss, $result, $kpi );
			},
			'No se pudo consolidar. Revisa el registro del servidor.'
		);
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		[ $ic, $ic_iss, $result, $kpi ] = $run;

		$files = array();
		foreach ( $uploads as $code => $u ) {
			$files[ $code ] = array(
				'id'        => $u['id'],
				'file_name' => $u['file_name'],
				'sha256'    => $u['sha256'],
				'sent_at'   => $u['sent_at'],
			);
		}
		$id = Store::save_result(
			$key,
			array(
				'version' => $v,
				'forced'      => (bool) $missing || (bool) $provisional,
				'missing'     => $missing,
				'provisional' => $provisional,
				'uploads' => $files,
				'result'  => $result,
				'kpi'     => $kpi,
				'ic'      => $ic,
			),
			$uid
		);
		if ( ! $id ) {
			return self::err( 'No se pudo guardar el resultado.', 500 );
		}
		Store::replace_ic_issues( $key, $ic_iss );
		if ( ! $provisional ) {
			Store::set_version_status( $key, 'consolidado' );
		}
		Log::write( 'consolidar', 'result', $id, array( 'version' => $key, 'paises' => array_keys( $files ), 'faltan' => $missing, 'ic' => count( $ic_iss ) ) );
		return array(
			'result_id' => $id,
			'result'    => self::result_out( Store::result( $id ) ),
		);
	}

	/** Resultado para la API con las tasas de la versión y los hallazgos abiertos. */
	private static function result_out( ?array $row ): ?array {
		if ( ! $row ) {
			return null;
		}
		$d = $row['data'];
		return array(
			'id'         => $row['id'],
			'version'    => Store::version( $row['version'] ),
			'created_at' => $row['created_at'],
			'created_by' => Log::user_name( $row['created_by'] ),
			'forced'     => (bool) ( $d['forced'] ?? false ),
			'missing'    => (array) ( $d['missing'] ?? array() ),
			'provisional' => (array) ( $d['provisional'] ?? array() ),
			'uploads'    => (array) ( $d['uploads'] ?? array() ),
			'rates'      => (array) ( Store::corporate()['rates'][ $row['version'] ] ?? array() ),
			'result'     => (array) ( $d['result'] ?? array() ),
			'kpi'        => (array) ( $d['kpi'] ?? array() ),
			'ic'         => (array) ( $d['ic'] ?? array() ),
			'issues'     => Store::version_issues( $row['version'], true ),
		);
	}

	public static function result_get( \WP_REST_Request $r ) {
		$row = Store::result( self::int( $r, 'id' ) );
		return $row ? self::result_out( $row ) : self::not_found( 'El resultado' );
	}

	public static function result_latest( \WP_REST_Request $r ) {
		$v = self::version_param( $r );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$row = Store::latest_result( $v['key'] );
		return $row ? self::result_out( $row ) : self::err( 'Aún no hay consolidación para ' . $v['label'] . '.', 404, 'csl_not_found' );
	}

	private static function latest_or_err( array $v ) {
		$row = Store::latest_result( $v['key'] );
		return $row ? $row : self::err( 'Aún no hay consolidación para ' . $v['label'] . '.', 404, 'csl_not_found' );
	}

	/* =====================================================================
	 * Trazabilidad
	 * ===================================================================*/

	/** GET /trace?version=&account=&company=&month= → [{company, file, upload_id, sheet, row, col, month, local, rate, usd}]. */
	public static function trace( \WP_REST_Request $r ) {
		$v = self::version_param( $r );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$account = self::str( $r, 'account', 30 );
		if ( '' === $account ) {
			return self::err( 'Indica la cuenta a rastrear.' );
		}
		$company = strtoupper( self::str( $r, 'company', 20 ) );
		$month   = '' !== (string) $r->get_param( 'month' ) ? (int) $r->get_param( 'month' ) : null;
		$row     = self::latest_or_err( $v );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$files = (array) ( $row['data']['uploads'] ?? array() );
		$out   = array();
		foreach ( (array) ( $row['data']['result']['lines'] ?? array() ) as $line ) {
			if ( (string) ( $line['account'] ?? ( $line['code'] ?? '' ) ) !== $account ) {
				continue;
			}
			foreach ( (array) ( $line['sources'] ?? array() ) as $s ) {
				$code = strtoupper( (string) ( $s['company'] ?? '' ) );
				$m    = isset( $s['month'] ) ? (int) $s['month'] : null;
				if ( ( '' !== $company && $code !== $company ) || ( null !== $month && $m !== $month ) ) {
					continue;
				}
				$src   = (array) ( $s['src'] ?? array() );
				$out[] = array(
					'company'   => $code,
					'file'      => $files[ $code ]['file_name'] ?? '',
					'upload_id' => $files[ $code ]['id'] ?? null,
					'sheet'     => $s['sheet'] ?? ( $src['sheet'] ?? '' ),
					'row'       => $s['row'] ?? ( $src['row'] ?? null ),
					'col'       => $s['col'] ?? ( $src['col'] ?? null ),
					'month'     => $m,
					'label'     => $s['label'] ?? null,
					'local'     => $s['local'] ?? ( $s['value'] ?? null ),
					'rate'      => $s['rate'] ?? null,
					'usd'       => $s['usd'] ?? null,
				);
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Desviaciones
	 * ===================================================================*/

	private static function compare_versions( array $va, array $vb, string $company ) {
		$ra = self::latest_or_err( $va );
		if ( is_wp_error( $ra ) ) {
			return $ra;
		}
		$rb = self::latest_or_err( $vb );
		if ( is_wp_error( $rb ) ) {
			return $rb;
		}
		$ctx = array(
			'company'    => '' !== $company ? $company : null,
			'corporate'  => Store::corporate(),
			'a'          => $va['key'],
			'b'          => $vb['key'],
			'datasets_a' => Store::result_datasets( $ra ),
			'datasets_b' => Store::result_datasets( $rb ),
		);
		$out = self::engine(
			fn() => EngineBridge::variance( (array) ( $ra['data']['result'] ?? array() ), (array) ( $rb['data']['result'] ?? array() ), $ctx ),
			'No se pudieron calcular las desviaciones.'
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$out['meta'] = array(
			'a' => array( 'version' => $va, 'result_id' => $ra['id'] ),
			'b' => array( 'version' => $vb, 'result_id' => $rb['id'] ),
		);
		return $out;
	}

	/** GET /variance?a=&b=&company= (claves de versión; sin b se usa la versión base de a). */
	public static function variance( \WP_REST_Request $r ) {
		$va = Store::version( sanitize_key( (string) $r->get_param( 'a' ) ) );
		$bk = sanitize_key( (string) $r->get_param( 'b' ) );
		$vb = '' !== $bk ? Store::version( $bk ) : ( $va ? Store::base_version( $va['key'] ) : null );
		if ( ! $va || ! $vb ) {
			return self::err( 'Elige las dos versiones a comparar.' );
		}
		return self::compare_versions( $va, $vb, strtoupper( self::str( $r, 'company', 20 ) ) );
	}

	/* =====================================================================
	 * Indicadores
	 * ===================================================================*/

	/** Propuestas recalculadas, o las presentadas tal cual si el motor aún no las recalcula. */
	private static function proposals_list( array $corp ): array {
		$re = self::soft( 'proposals', fn() => EngineBridge::proposals( $corp ) );
		return null !== $re
			? array( 'recalculated' => true, 'items' => array_values( $re['items'] ?? $re ) )
			: array( 'recalculated' => false, 'items' => $corp['proposals'] );
	}

	public static function kpi( \WP_REST_Request $r ) {
		$v = self::version_param( $r );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$row = self::latest_or_err( $v );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		self::$unavailable = array();
		$corp              = Store::corporate();
		$kpi               = (array) ( $row['data']['kpi'] ?? array() );
		$result            = (array) ( $row['data']['result'] ?? array() );
		$datasets          = Store::result_datasets( $row );

		$copied = array();
		foreach ( $datasets as $code => $ds ) {
			$kc              = (array) ( $kpi['companies'][ $code ] ?? array() );
			$copied[ $code ] = self::soft( 'kpi_copied', fn() => EngineBridge::kpi_copied( $ds, $kc, array( 'corporate' => $corp, 'version' => $v['key'] ) ) );
		}
		// Sin Kpi::copied, los KPI_COPY_* que ya marcó el validador.
		if ( isset( self::$unavailable['kpi_copied'] ) ) {
			$copied = array();
			foreach ( Store::version_issues( $v['key'], false, null, 'indicadores' ) as $i ) {
				$copied[ $i['company'] ]['issues'][] = $i;
			}
		}

		$impacts = array();
		foreach ( Store::version_issues( $v['key'], true ) as $issue ) {
			if ( null === $issue['expected'] && null === $issue['impact_usd'] ) {
				continue;
			}
			$kc  = (array) ( $kpi['companies'][ $issue['company'] ] ?? array() );
			$imp = $kc ? self::soft(
				'issue_impact',
				fn() => EngineBridge::issue_impact(
					$issue,
					$kc,
					array(
						'company'   => $corp['companies'][ $issue['company'] ] ?? null,
						'corporate' => $corp,
						'version'   => $v['key'],
						'dataset'   => $datasets[ $issue['company'] ] ?? null,
					)
				)
			) : null;
			if ( ( is_array( $imp ) && ! empty( $imp['applies'] ) ) || null !== $issue['impact_usd'] ) {
				$impacts[] = array(
					'issue_id'   => $issue['id'],
					'company'    => $issue['company'],
					'code'       => $issue['code'],
					'category'   => $issue['category'],
					'sheet'      => $issue['sheet'],
					'row'        => $issue['row'],
					'col'        => $issue['col'],
					'month'      => $issue['month'],
					'concept'    => $issue['concept'],
					'message'    => $issue['message'],
					'impact_usd' => $issue['impact_usd'],
					'impact'     => $imp,
				);
			}
		}

		$scenarios = self::soft( 'history_scenario', fn() => EngineBridge::history_scenarios( $result, array( 'corporate' => $corp, 'version' => $v['key'], 'kpi' => $kpi ) ) );

		return array_merge(
			$kpi,
			array(
				'result_id'   => $row['id'],
				'version'     => $v,
				'copied'      => $copied,
				'proposals'   => self::proposals_list( $corp ),
				'impacts'     => $impacts,
				'scenarios'   => $scenarios,
				'unavailable' => self::$unavailable,
			)
		);
	}

	/* =====================================================================
	 * Resumen ejecutivo
	 * ===================================================================*/

	private static function money( float $v ): string {
		$abs = abs( $v );
		if ( $abs >= 1e6 ) {
			return number_format( $v / 1e6, 1, '.', ',' ) . ' M USD';
		}
		if ( $abs >= 1e3 ) {
			return number_format( $v / 1e3, 0, '.', ',' ) . ' mil USD';
		}
		return number_format( $v, 0, '.', ',' ) . ' USD';
	}

	public static function summary( \WP_REST_Request $r ) {
		$v = self::version_param( $r );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$row = self::latest_or_err( $v );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		self::$unavailable = array();
		$d                 = $row['data'];
		$kpi               = (array) ( $d['kpi'] ?? array() );
		$result            = (array) ( $d['result'] ?? array() );
		$corp              = Store::corporate();

		$lights = array();
		foreach ( (array) ( $kpi['companies'] ?? array() ) as $code => $k ) {
			$lights[] = array(
				'code'    => (string) $code,
				'name'    => $corp['companies'][ $code ]['name'] ?? (string) $code,
				'light'   => $k['light'] ?? null,
				'roic'    => $k['roic'] ?? null,
				'wacc'    => $k['wacc'] ?? null,
				'eva'     => $k['eva'] ?? null,
				'reasons' => $k['reasons'] ?? array(),
			);
		}

		// Top 3 desviaciones frente a la versión base (§11), si está consolidada.
		$top  = array();
		$note = '';
		$base = Store::base_version( $v['key'] );
		if ( $base && Store::latest_result_head( $base['key'] ) ) {
			$var = self::compare_versions( $v, $base, '' );
			if ( is_wp_error( $var ) ) {
				$note = $var->get_error_message();
			} else {
				$top = self::top_deviations( $var, 3 );
			}
		} else {
			$note = 'No hay una versión base consolidada para comparar.';
		}

		// Decisiones sugeridas por reglas simples.
		$decisions = array();
		foreach ( (array) ( $kpi['companies'] ?? array() ) as $code => $k ) {
			if ( isset( $k['eva'] ) && is_numeric( $k['eva'] ) && $k['eva'] < 0 ) {
				$decisions[] = array(
					'company' => (string) $code,
					'rule'    => 'eva_negativo',
					'text'    => 'Revisar capital invertido o proyectos de ' . ( $corp['companies'][ $code ]['name'] ?? $code ) . ': su EVA es negativo (' . self::money( (float) $k['eva'] ) . ').',
				);
			}
		}
		$open = Store::version_issues( $v['key'], true );
		$crit = array();
		foreach ( $open as $i ) {
			if ( 'critico' === $i['severity'] ) {
				$crit[ $i['company'] ] = true;
			}
		}
		if ( $crit ) {
			$decisions[] = array(
				'company' => null,
				'rule'    => 'criticos_abiertos',
				'text'    => 'Corregir los errores críticos abiertos de ' . implode( ', ', array_keys( $crit ) ) . ' antes de cerrar la versión.',
			);
		}
		$unrec = (float) ( $result['ic_unreconciled'] ?? 0 );
		if ( abs( $unrec ) >= 1 ) {
			$decisions[] = array(
				'company' => null,
				'rule'    => 'ic_sin_conciliar',
				'text'    => 'Conciliar la diferencia intercompañía sin explicar de ' . self::money( $unrec ) . '.',
			);
		}
		$props = self::proposals_list( $corp );
		if ( $props['recalculated'] ) {
			foreach ( $props['items'] as $p ) {
				$rec = (string) ( $p['decision_recalculada'] ?? ( $p['decision_recalculated'] ?? '' ) );
				$pro = (string) ( $p['decision_propuesta'] ?? ( $p['decision'] ?? '' ) );
				if ( '' !== $rec && '' !== $pro && $rec !== $pro ) {
					$decisions[] = array(
						'company' => $p['site'] ?? null,
						'rule'    => 'propuesta_no_se_sostiene',
						'text'    => 'La propuesta ' . ( $p['id'] ?? '' ) . ' (' . ( $p['site'] ?? '' ) . ') se presentó para «' . $pro . '» y recalculada da «' . $rec . '».',
					);
				}
			}
		}
		$g = (array) ( $kpi['group'] ?? array() );
		if ( isset( $g['roic'], $g['wacc'] ) && is_numeric( $g['roic'] ) && is_numeric( $g['wacc'] ) && $g['roic'] < $g['wacc'] ) {
			$decisions[] = array(
				'company' => null,
				'rule'    => 'grupo_bajo_wacc',
				'text'    => 'El grupo no cubre su costo de capital: priorizar proyectos con ROIC mayor al WACC.',
			);
		}
		if ( ! $decisions ) {
			$decisions[] = array(
				'company' => null,
				'rule'    => 'sin_alertas',
				'text'    => 'Mantener el plan: no hay alertas mayores en esta versión.',
			);
		}

		return array(
			'version'     => $v,
			'result_id'   => $row['id'],
			'created_at'  => $row['created_at'],
			'forced'      => (bool) ( $d['forced'] ?? false ),
			'missing'     => (array) ( $d['missing'] ?? array() ),
			'provisional' => (array) ( $d['provisional'] ?? array() ),
			'totals'      => (array) ( $result['totals'] ?? array() ),
			'group'       => $g,
			'lights'      => $lights,
			'top'         => $top,
			'base'        => $base,
			'note'        => $note,
			'decision'    => $decisions[0]['text'],
			'decisions'   => $decisions,
			'openIssues'  => self::findings_counts( $open ),
			'actions'     => Store::actions( $v['key'], true ),
			'unavailable' => self::$unavailable,
		);
	}

	/** Las N desviaciones de mayor |delta| entre las de país y las de cuenta del grupo. */
	private static function top_deviations( array $var, int $n ): array {
		$cand = array();
		foreach ( (array) ( $var['companies'] ?? array() ) as $code => $c ) {
			if ( isset( $c['delta'] ) && is_numeric( $c['delta'] ) ) {
				$cand[] = array(
					'kind'    => 'ventas_pais',
					'company' => (string) $code,
					'account' => $c['account'] ?? null,
					'label'   => 'Ventas de ' . $code,
					'a'       => $c['a'] ?? null,
					'b'       => $c['b'] ?? null,
					'delta'   => (float) $c['delta'],
					'text'    => (string) ( $c['text'] ?? '' ),
				);
			}
		}
		foreach ( (array) ( $var['accounts'] ?? array() ) as $acc ) {
			if ( ! is_array( $acc ) || ! isset( $acc['delta'] ) || ! is_numeric( $acc['delta'] ) ) {
				continue;
			}
			$name   = (string) ( $acc['name'] ?? ( $acc['account'] ?? '' ) );
			$delta  = (float) $acc['delta'];
			$b      = $acc['b'] ?? null;
			$pct    = is_numeric( $b ) && 0.0 !== (float) $b ? round( $delta / abs( (float) $b ) * 100, 1 ) : null;
			$cand[] = array(
				'kind'    => 'cuenta_grupo',
				'company' => null,
				'account' => $acc['account'] ?? null,
				'label'   => $name,
				'a'       => $acc['a'] ?? null,
				'b'       => $b,
				'delta'   => $delta,
				'text'    => (string) ( $acc['text'] ?? ( $name . ( $delta >= 0 ? ' sube ' : ' baja ' ) . self::money( abs( $delta ) ) . ( null !== $pct ? ' (' . abs( $pct ) . ' %)' : '' ) . '.' ) ),
			);
		}
		usort( $cand, fn( $x, $y ) => abs( $y['delta'] ) <=> abs( $x['delta'] ) );
		return array_slice( $cand, 0, $n );
	}

	/* =====================================================================
	 * Hallazgos
	 * ===================================================================*/

	/** Conteos de una lista de hallazgos: total, por severidad, por categoría y por país. */
	public static function findings_counts( array $issues ): array {
		$by_cat  = array_fill_keys( array_keys( Store::CATEGORIES ), 0 );
		$by_comp = array();
		$crit    = 0;
		foreach ( $issues as $i ) {
			$cat = $i['category'] ?? '';
			if ( isset( $by_cat[ $cat ] ) ) {
				++$by_cat[ $cat ];
			}
			$co = '' !== ( $i['company'] ?? '' ) ? $i['company'] : '—';
			if ( ! isset( $by_comp[ $co ] ) ) {
				$by_comp[ $co ] = array_fill_keys( array_keys( Store::CATEGORIES ), 0 ) + array( 'total' => 0, 'critical' => 0 );
			}
			if ( isset( $by_comp[ $co ][ $cat ] ) ) {
				++$by_comp[ $co ][ $cat ];
			}
			++$by_comp[ $co ]['total'];
			if ( 'critico' === ( $i['severity'] ?? '' ) ) {
				++$crit;
				++$by_comp[ $co ]['critical'];
			}
		}
		return array(
			'total'       => count( $issues ),
			'critical'    => $crit,
			'warning'     => count( $issues ) - $crit,
			'by_category' => $by_cat,
			'by_company'  => $by_comp,
		);
	}

	/**
	 * GET /findings?version=&company=&category=&severity=&status=
	 * Todos los hallazgos de la versión (subidas vigentes + intercompañía), con conteos por categoría y la comparación
	 * con historial_ciclos (History::quality). Un usuario País solo ve sus empresas.
	 */
	public static function findings( \WP_REST_Request $r ) {
		$v = self::version_param( $r );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		self::$unavailable = array();
		$vis               = Roles::visible_company_ids();
		$ids               = $vis;
		$code              = strtoupper( self::str( $r, 'company', 20 ) );
		if ( '' !== $code ) {
			$c = Store::company_by_code( $code );
			if ( ! $c || ( null !== $vis && ! in_array( $c['id'], $vis, true ) ) ) {
				return self::deny();
			}
			$ids = array( $c['id'] );
		}
		$category = self::str( $r, 'category', 30 );
		$category = isset( Store::CATEGORIES[ $category ] ) ? $category : '';
		$severity = in_array( self::str( $r, 'severity', 10 ), array( 'critico', 'aviso' ), true ) ? self::str( $r, 'severity', 10 ) : '';
		$only_open = 'abierta' === self::str( $r, 'status', 20 );

		$items = Store::version_issues( $v['key'], $only_open, $ids, $category, $severity );
		$all   = ( '' === $category && '' === $severity ) ? $items : Store::version_issues( $v['key'], $only_open, $ids );
		$corp  = Store::corporate();

		$by_country = array();
		foreach ( $all as $i ) {
			$by_country[ '' !== $i['company'] ? $i['company'] : '—' ][] = $i;
		}
		$history = ( Roles::is_corp() || Roles::is_board() )
			? self::soft( 'history_quality', fn() => EngineBridge::history_quality( $by_country, $corp ) )
			: null;

		return array(
			'version'       => $v,
			'items'         => $items,
			'counts'        => self::findings_counts( $all ),
			'categories'    => Store::CATEGORIES,
			'history'       => $history,
			'historyCycles' => ( Roles::is_corp() || Roles::is_board() ) ? $corp['history_cycles'] : array(),
			'unavailable'   => self::$unavailable,
		);
	}

	/* =====================================================================
	 * Propuestas e historial
	 * ===================================================================*/

	/** GET /proposals: P1..P4 presentadas y, si el motor está, recalculadas con la decisión que se sostiene. */
	public static function proposals() {
		self::$unavailable = array();
		$out               = self::proposals_list( Store::corporate() );
		$out['presented']  = Store::corporate()['proposals'];
		$out['unavailable'] = self::$unavailable;
		return $out;
	}

	/** GET /history: historial_ciclos, historial_indicadores y la serie evolutiva con las versiones consolidadas. */
	public static function history() {
		self::$unavailable = array();
		$corp              = Store::corporate();
		$kpi_by_version    = array();
		foreach ( Store::versions() as $v ) {
			$row = Store::latest_result( $v['key'] );
			if ( $row ) {
				$kpi_by_version[ $v['key'] ] = (array) ( $row['data']['kpi'] ?? array() );
			}
		}
		return array(
			'cycles'      => $corp['history_cycles'],
			'kpis'        => $corp['history_kpis'],
			'trend'       => self::soft( 'history_trend', fn() => EngineBridge::history_trend( $corp, $kpi_by_version ) ),
			'versions'    => array_keys( $kpi_by_version ),
			'unavailable' => self::$unavailable,
		);
	}

	/* =====================================================================
	 * Acciones
	 * ===================================================================*/

	public static function actions( \WP_REST_Request $r ) {
		return Store::actions( sanitize_key( (string) $r->get_param( 'version' ) ), self::bool( $r, 'open' ) );
	}

	public static function action_save( \WP_REST_Request $r ) {
		$id  = self::int( $r, 'id' );
		$old = $id ? Store::action( $id ) : null;
		if ( $id && ! $old ) {
			return self::not_found( 'La acción' );
		}
		$d = array();
		if ( ! $id || null !== $r->get_param( 'title' ) ) {
			$d['title'] = self::str( $r, 'title', 255 );
			if ( '' === $d['title'] ) {
				return self::err( 'Escribe qué hay que hacer.' );
			}
		}
		if ( ! $id ) {
			$key = sanitize_key( (string) $r->get_param( 'version' ) );
			if ( '' !== $key && ! Store::version( $key ) ) {
				return self::not_found( 'La versión' );
			}
			$d['version_key'] = $key;
			$d['created_by']  = get_current_user_id();
			$d['status']      = 'pendiente';
		}
		if ( null !== $r->get_param( 'owner' ) ) {
			$d['owner'] = self::str( $r, 'owner', 190 );
		}
		if ( null !== $r->get_param( 'due_date' ) ) {
			$d['due_date'] = self::date_or_null( $r->get_param( 'due_date' ) );
		}
		if ( null !== $r->get_param( 'effect' ) ) {
			$d['effect'] = self::text( $r, 'effect', 2000 );
		}
		if ( null !== $r->get_param( 'status' ) ) {
			$st = self::str( $r, 'status', 20 );
			if ( ! in_array( $st, array( 'pendiente', 'en_curso', 'hecha' ), true ) ) {
				return self::err( 'Estado no válido: usa pendiente, en_curso o hecha.' );
			}
			$d['status'] = $st;
		}
		$new = Store::save_action( $d, $id );
		if ( ! $new ) {
			return self::err( 'No se pudo guardar la acción.', 500 );
		}
		Log::write( $id ? 'editar' : 'crear', 'action', $new, array( 'fields' => array_keys( $d ), 'status' => $d['status'] ?? ( $old['status'] ?? '' ) ) );
		return Store::action( $new );
	}

	/* =====================================================================
	 * Calidad de la información (ciclo actual)
	 * ===================================================================*/

	/**
	 * Por país y versión: críticos y avisos de la última subida, número de subidas y horas/días entre la primera subida
	 * y el envío. Más un ranking por país.
	 */
	public static function quality() {
		$companies = array();
		foreach ( Store::companies() as $c ) {
			$companies[ $c['id'] ] = $c;
		}
		$versions = array();
		foreach ( Store::versions() as $v ) {
			$versions[ $v['key'] ] = $v;
		}
		$groups = array();
		foreach ( array_reverse( Store::uploads() ) as $u ) {
			$k = $u['version'] . '|' . $u['company_id'];
			if ( ! isset( $groups[ $k ] ) ) {
				$groups[ $k ] = array( 'first' => $u['uploaded_at'], 'n' => 0, 'last' => $u );
			}
			++$groups[ $k ]['n'];
			if ( ! $u['superseded'] ) {
				$groups[ $k ]['last'] = $u;
			}
		}
		$rows    = array();
		$ranking = array();
		foreach ( $groups as $g ) {
			$u = $g['last'];
			$c = $companies[ $u['company_id'] ] ?? null;
			$v = $versions[ $u['version'] ] ?? null;
			if ( ! $c || ! $v ) {
				continue;
			}
			$hours  = $u['sent_at'] ? round( ( strtotime( $u['sent_at'] . ' UTC' ) - strtotime( $g['first'] . ' UTC' ) ) / 3600, 1 ) : null;
			$rows[] = array(
				'version'         => $v['key'],
				'versionLabel'    => $v['label'],
				'company_id'      => $c['id'],
				'code'            => $c['code'],
				'name'            => $c['name'],
				'country'         => $c['country'],
				'status'          => $u['status'],
				'criticals'       => $u['criticals'],
				'warnings'        => $u['warnings'],
				'uploads'         => $g['n'],
				'first_upload_at' => $g['first'],
				'sent_at'         => $u['sent_at'],
				'hours_to_send'   => $hours,
				'days_to_send'    => null === $hours ? null : round( $hours / 24, 1 ),
			);
			$key = $c['code'];
			if ( ! isset( $ranking[ $key ] ) ) {
				$ranking[ $key ] = array( 'code' => $c['code'], 'country' => $c['country'], 'criticals' => 0, 'warnings' => 0, 'uploads' => 0, 'hours' => array() );
			}
			$ranking[ $key ]['criticals'] += $u['criticals'];
			$ranking[ $key ]['warnings']  += $u['warnings'];
			$ranking[ $key ]['uploads']   += $g['n'];
			if ( null !== $hours ) {
				$ranking[ $key ]['hours'][] = $hours;
			}
		}
		foreach ( $ranking as &$rk ) {
			$rk['avg_hours_to_send'] = $rk['hours'] ? round( array_sum( $rk['hours'] ) / count( $rk['hours'] ), 1 ) : null;
			unset( $rk['hours'] );
		}
		unset( $rk );
		$ranking = array_values( $ranking );
		usort( $ranking, fn( $a, $b ) => array( $a['criticals'], $a['warnings'], $a['uploads'] ) <=> array( $b['criticals'], $b['warnings'], $b['uploads'] ) );
		return array( 'rows' => $rows, 'ranking' => $ranking );
	}
}
