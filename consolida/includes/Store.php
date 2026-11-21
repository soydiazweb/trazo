<?php
namespace Consolida;

/**
 * Lectura y escritura de todas las tablas (contrato v2 §15) y armado de las entradas del motor: el arreglo
 * `$corporate` (§4) se reconstruye desde las tablas, y los Datasets (§6) salen de las subidas vigentes.
 * Todo sale de las tablas: no hay países, monedas ni versiones fijos en el código.
 * Nunca hace SELECT * sobre uploads (binario y Dataset pesan): usa UPLOAD_COLS.
 */
class Store {
	const UPLOAD_COLS = 'id, version_key, company_id, file_name, sha256, size, uploaded_by, uploaded_at, status, approved_by, approved_at, sent_at, criticals, warnings, superseded';

	/** Categorías de issue (las 6 de historial_ciclos). */
	const CATEGORIES = array(
		'formato_escala'    => 'Formato y escala',
		'catalogo_periodo'  => 'Catálogo y periodo',
		'calculos_totales'  => 'Cálculos y totales',
		'intercompania'     => 'Intercompañía',
		'tipo_cambio'       => 'Tipo de cambio',
		'indicadores'       => 'Indicadores copiados',
	);

	private static ?array $corporate_cache = null;

	/* =====================================================================
	 * Utilidades
	 * ===================================================================*/

	/** JSON seguro (los NAN/INF del motor no rompen el guardado). */
	public static function json( $v ): string {
		$s = wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return false === $s ? 'null' : $s;
	}

	public static function decode( ?string $s ) {
		if ( null === $s || '' === $s ) {
			return null;
		}
		$v = json_decode( $s, true );
		return JSON_ERROR_NONE === json_last_error() ? $v : null;
	}

	private static function ids_in( array $ids ): string {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		return $ids ? implode( ',', $ids ) : '0';
	}

	private static function f( $v ): ?float {
		return is_numeric( $v ) && is_finite( (float) $v ) ? (float) $v : null;
	}

	/** Categoría por prefijo del código si el motor no la trae. */
	public static function category_for( string $code ): string {
		$map = array(
			'FMT_DATE' => 'catalogo_periodo',
			'FMT_'     => 'formato_escala',
			'CAT_'     => 'catalogo_periodo',
			'DATA_DUP' => 'catalogo_periodo',
			'SIGN'     => 'catalogo_periodo',
			'DATA_'    => 'calculos_totales',
			'CALC_'    => 'calculos_totales',
			'IC_'      => 'intercompania',
			'FX_'      => 'tipo_cambio',
			'KPI_'     => 'indicadores',
			'UPLOAD_'  => 'catalogo_periodo',
		);
		foreach ( $map as $prefix => $cat ) {
			if ( str_starts_with( $code, $prefix ) ) {
				return $cat;
			}
		}
		return 'calculos_totales';
	}

	/* =====================================================================
	 * Corporativo (§4): importación y reconstrucción
	 * ===================================================================*/

	/**
	 * Guarda la salida de Corporate::load en las tablas. Reemplaza tasas, catálogo, productos, canales, propuestas e
	 * historial; actualiza o crea empresas por código; crea las versiones que aparezcan en las tasas.
	 * Los perfiles de lectura no se tocan (solo se siembran por defecto donde no existan).
	 */
	public static function import_corporate( array $corp ): array {
		global $wpdb;
		$now = Schema::now();
		$n   = array( 'companies' => 0, 'rates' => 0, 'versions' => 0, 'catalog' => 0, 'products' => 0, 'channels' => 0, 'proposals' => 0, 'history_cycles' => 0, 'history_kpis' => 0 );

		foreach ( (array) ( $corp['companies'] ?? array() ) as $code => $c ) {
			$code = strtoupper( (string) ( $c['code'] ?? $code ) );
			if ( '' === $code ) {
				continue;
			}
			$row = array(
				'code'             => $code,
				'number'           => (int) ( $c['number'] ?? 0 ),
				'name'             => mb_substr( (string) ( $c['name'] ?? $code ), 0, 190 ),
				'country'          => mb_substr( (string) ( $c['country'] ?? '' ), 0, 100 ),
				'currency'         => strtoupper( substr( (string) ( $c['currency'] ?? 'USD' ), 0, 3 ) ),
				'tax'              => (float) ( $c['tax'] ?? 0 ),
				'wacc'             => (float) ( $c['wacc'] ?? 0 ),
				'ownership'        => (float) ( $c['ownership'] ?? 1 ),
				'minority'         => (float) ( $c['minority'] ?? 0 ),
				'minority_partner' => isset( $c['minority_partner'] ) && '' !== (string) $c['minority_partner'] ? mb_substr( (string) $c['minority_partner'], 0, 190 ) : null,
				'scale'            => mb_substr( (string) ( $c['scale'] ?? 'unidades' ), 0, 20 ),
				'data_owner'       => mb_substr( (string) ( $c['data_owner'] ?? '' ), 0, 190 ),
				'approver'         => mb_substr( (string) ( $c['approver'] ?? '' ), 0, 190 ),
				'updated_at'       => $now,
			);
			$old = self::company_by_code( $code );
			if ( $old ) {
				$wpdb->update( Schema::t( 'companies' ), $row, array( 'id' => $old['id'] ) );
			} else {
				$row['created_at'] = $now;
				$wpdb->insert( Schema::t( 'companies' ), $row );
			}
			++$n['companies'];
		}

		Schema::truncate( 'rates' );
		foreach ( (array) ( $corp['rates'] ?? array() ) as $vkey => $by_cur ) {
			$vkey = (string) $vkey;
			if ( ! self::valid_key( $vkey ) ) {
				continue;
			}
			if ( ! self::version( $vkey ) ) {
				self::ensure_version( $vkey );
				++$n['versions'];
			}
			foreach ( (array) $by_cur as $cur => $months ) {
				$cur = strtoupper( (string) $cur );
				if ( 'USD' === $cur || ! preg_match( '/^[A-Z]{3}$/', $cur ) ) {
					continue;
				}
				foreach ( (array) $months as $m => $r ) {
					$m = (int) $m;
					if ( $m < 0 || $m > 12 || ! is_array( $r ) ) {
						continue;
					}
					$status = strtolower( (string) ( $r['status'] ?? 'aprobada' ) );
					self::save_rate(
						$vkey,
						$cur,
						$m,
						self::f( $r['budget'] ?? null ),
						self::f( $r['avg'] ?? null ),
						self::f( $r['close'] ?? null ),
						'aprobada' === $status ? 'aprobada' : 'borrador',
						(string) ( $r['approved_by'] ?? '' ),
						(string) ( $r['approved_at'] ?? '' )
					);
					++$n['rates'];
				}
			}
		}

		Schema::truncate( 'catalog' );
		foreach ( (array) ( $corp['catalog'] ?? array() ) as $code => $a ) {
			$code = (string) ( $a['code'] ?? $code );
			$wpdb->insert(
				Schema::t( 'catalog' ),
				array(
					'code'         => substr( $code, 0, 20 ),
					'name'         => mb_substr( (string) ( $a['name'] ?? '' ), 0, 190 ),
					'type'         => substr( (string) ( $a['type'] ?? '' ), 0, 30 ),
					'subtype'      => substr( (string) ( $a['subtype'] ?? '' ), 0, 40 ),
					'operating'    => empty( $a['operating'] ) ? 0 : 1,
					'is_current'   => array_key_exists( 'current', $a ) && null !== $a['current'] ? ( $a['current'] ? 1 : 0 ) : null,
					'intercompany' => empty( $a['intercompany'] ) ? 0 : 1,
					'sign'         => (int) ( $a['sign'] ?? 1 ) < 0 ? -1 : 1,
					'data'         => self::json( $a ),
				)
			);
			++$n['catalog'];
		}

		Schema::truncate( 'products' );
		foreach ( (array) ( $corp['products'] ?? array() ) as $code => $p ) {
			$wpdb->insert(
				Schema::t( 'products' ),
				array(
					'code'      => substr( (string) $code, 0, 30 ),
					'name'      => mb_substr( (string) ( $p['name'] ?? '' ), 0, 190 ),
					'name_en'   => mb_substr( (string) ( $p['name_en'] ?? '' ), 0, 190 ),
					'line_name' => mb_substr( (string) ( $p['line'] ?? '' ), 0, 60 ),
					'data'      => self::json( $p ),
				)
			);
			++$n['products'];
		}

		Schema::truncate( 'channels' );
		foreach ( (array) ( $corp['channels'] ?? array() ) as $code => $ch ) {
			$wpdb->insert(
				Schema::t( 'channels' ),
				array(
					'code'    => substr( (string) $code, 0, 30 ),
					'name'    => mb_substr( (string) ( $ch['name'] ?? '' ), 0, 190 ),
					'name_en' => mb_substr( (string) ( $ch['name_en'] ?? '' ), 0, 190 ),
					'data'    => self::json( $ch ),
				)
			);
			++$n['channels'];
		}

		Schema::truncate( 'proposals' );
		foreach ( (array) ( $corp['proposals'] ?? array() ) as $i => $p ) {
			$wpdb->insert(
				Schema::t( 'proposals' ),
				array(
					'pid'  => substr( (string) ( $p['id'] ?? ( 'P' . ( (int) $i + 1 ) ) ), 0, 30 ),
					'site' => substr( (string) ( $p['site'] ?? '' ), 0, 20 ),
					'data' => self::json( $p ),
				)
			);
			++$n['proposals'];
		}

		foreach ( array( 'history_cycles', 'history_kpis' ) as $t ) {
			Schema::truncate( $t );
			foreach ( (array) ( $corp[ $t ] ?? array() ) as $row ) {
				$row = (array) $row;
				$cc  = strtoupper( (string) ( $row['company'] ?? ( $row['country_code'] ?? ( $row['pais_codigo'] ?? '' ) ) ) );
				$wpdb->insert(
					Schema::t( $t ),
					array(
						'country_code' => substr( $cc, 0, 20 ),
						'data'         => self::json( $row ),
					)
				);
				++$n[ $t ];
			}
		}
		self::$corporate_cache = null;
		return $n;
	}

	/** Reconstruye el arreglo $corporate (§4) desde las tablas. Se guarda en memoria durante la petición. */
	public static function corporate(): array {
		if ( null !== self::$corporate_cache ) {
			return self::$corporate_cache;
		}
		global $wpdb;
		$companies = array();
		foreach ( self::companies() as $c ) {
			$companies[ $c['code'] ] = $c;
		}
		$rates = array();
		$rows  = (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::t( 'rates' ) . ' ORDER BY version_key, currency, month', ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$entry = self::rate_from_row( $r );
			if ( 0 === (int) $r['month'] ) {
				// Saldo inicial: solo el cierre (como lo entrega Corporate::load).
				$entry = array_filter( $entry, fn( $v, $k ) => null !== $v || 'close' === $k, ARRAY_FILTER_USE_BOTH );
			}
			$rates[ $r['version_key'] ][ $r['currency'] ][ (int) $r['month'] ] = $entry;
		}
		$catalog = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::t( 'catalog' ) . ' ORDER BY code', ARRAY_A ) as $r ) { // phpcs:ignore
			$catalog[ (string) $r['code'] ] = (array) ( self::decode( $r['data'] ) ?? array() ) + array(
				'code'         => (string) $r['code'],
				'name'         => (string) $r['name'],
				'type'         => (string) $r['type'],
				'subtype'      => (string) $r['subtype'],
				'operating'    => (bool) $r['operating'],
				'current'      => null === $r['is_current'] ? null : (bool) $r['is_current'],
				'intercompany' => (bool) $r['intercompany'],
				'sign'         => (int) $r['sign'],
			);
		}
		self::$corporate_cache = array(
			'companies'      => $companies,
			'rates'          => $rates,
			'catalog'        => $catalog,
			'products'       => self::keyed_data( 'products' ),
			'channels'       => self::keyed_data( 'channels' ),
			'proposals'      => self::list_data( 'proposals', 'pid' ),
			'history_cycles' => self::list_data( 'history_cycles', 'id' ),
			'history_kpis'   => self::list_data( 'history_kpis', 'id' ),
		);
		return self::$corporate_cache;
	}

	public static function flush_corporate(): void {
		self::$corporate_cache = null;
	}

	private static function keyed_data( string $table ): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT code, data FROM ' . Schema::t( $table ) . ' ORDER BY code', ARRAY_A ) as $r ) { // phpcs:ignore -- tabla fija.
			$out[ (string) $r['code'] ] = (array) ( self::decode( $r['data'] ) ?? array() );
		}
		return $out;
	}

	private static function list_data( string $table, string $order ): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT data FROM ' . Schema::t( $table ) . ' ORDER BY ' . $order, ARRAY_A ) as $r ) { // phpcs:ignore -- tabla y orden fijos.
			$out[] = (array) ( self::decode( $r['data'] ) ?? array() );
		}
		return $out;
	}

	public static function has_corporate(): bool {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::t( 'companies' ) ) > 0 // phpcs:ignore
			&& (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::t( 'catalog' ) ) > 0; // phpcs:ignore
	}

	/* =====================================================================
	 * Empresas
	 * ===================================================================*/

	public static function company_from_row( array $r ): array {
		return array(
			'id'               => (int) $r['id'],
			'code'             => (string) $r['code'],
			'number'           => (int) $r['number'],
			'name'             => (string) $r['name'],
			'country'          => (string) $r['country'],
			'currency'         => (string) $r['currency'],
			'tax'              => (float) $r['tax'],
			'wacc'             => (float) $r['wacc'],
			'ownership'        => (float) $r['ownership'],
			'minority'         => (float) $r['minority'],
			'minority_partner' => $r['minority_partner'],
			'scale'            => (string) $r['scale'],
			'data_owner'       => (string) $r['data_owner'],
			'approver'         => (string) $r['approver'],
		);
	}

	/** Empresas por número de país y código; con $ids solo esas (null = todas). */
	public static function companies( ?array $ids = null ): array {
		global $wpdb;
		$t     = Schema::t( 'companies' );
		$where = null !== $ids ? ' WHERE id IN (' . self::ids_in( $ids ) . ')' : '';
		$rows  = $wpdb->get_results( "SELECT * FROM $t$where ORDER BY number ASC, code ASC", ARRAY_A ); // phpcs:ignore -- ids enteros.
		return array_map( array( __CLASS__, 'company_from_row' ), (array) $rows );
	}

	public static function companies_by_code(): array {
		$out = array();
		foreach ( self::companies() as $c ) {
			$out[ $c['code'] ] = $c;
		}
		return $out;
	}

	public static function company( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'companies' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ? self::company_from_row( $r ) : null;
	}

	public static function company_by_code( string $code ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'companies' ) . ' WHERE code = %s', $code ), ARRAY_A );
		return $r ? self::company_from_row( $r ) : null;
	}

	public static function company_by_number( int $number ): ?array {
		global $wpdb;
		if ( $number <= 0 ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'companies' ) . ' WHERE number = %d LIMIT 1', $number ), ARRAY_A );
		return $r ? self::company_from_row( $r ) : null;
	}

	/** Crea ($id = 0) o actualiza una empresa con los campos dados. Devuelve el id. */
	public static function save_company( array $d, int $id = 0 ): int {
		global $wpdb;
		$t       = Schema::t( 'companies' );
		$allowed = array( 'code', 'number', 'name', 'country', 'currency', 'tax', 'wacc', 'ownership', 'minority', 'minority_partner', 'scale', 'data_owner', 'approver' );
		$row     = array_intersect_key( $d, array_flip( $allowed ) );
		$row['updated_at'] = Schema::now();
		self::$corporate_cache = null;
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = Schema::now();
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	public static function company_user_ids( int $company_id ): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . Schema::t( 'company_users' ) . ' WHERE company_id = %d', $company_id ) ) );
	}

	public static function set_company_users( int $company_id, array $user_ids ): void {
		global $wpdb;
		$t = Schema::t( 'company_users' );
		$wpdb->delete( $t, array( 'company_id' => $company_id ), array( '%d' ) );
		foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $uid ) {
			if ( $uid > 0 && get_userdata( $uid ) ) {
				$wpdb->insert( $t, array( 'company_id' => $company_id, 'user_id' => $uid ), array( '%d', '%d' ) );
			}
		}
	}

	public static function user_company_ids( int $uid ): array {
		global $wpdb;
		if ( $uid <= 0 ) {
			return array();
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT company_id FROM ' . Schema::t( 'company_users' ) . ' WHERE user_id = %d', $uid ) ) );
	}

	/** Monedas locales de las empresas dadas (sin USD). */
	public static function currencies( ?array $companies = null ): array {
		$companies = null === $companies ? self::companies() : $companies;
		$out       = array();
		foreach ( $companies as $c ) {
			$cur = strtoupper( (string) $c['currency'] );
			if ( '' !== $cur && 'USD' !== $cur ) {
				$out[ $cur ] = true;
			}
		}
		$out = array_keys( $out );
		sort( $out );
		return $out;
	}

	/* =====================================================================
	 * Perfiles de lectura (§5)
	 * ===================================================================*/

	/**
	 * Perfil de la empresa: el guardado en la tabla o, si no hay, el de Profiles::defaults().
	 *
	 * @return array{profile:?array,source:string,updated_at:?string,updated_by:int}
	 */
	public static function profile( array $company ): array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'profiles' ) . ' WHERE company_id = %d', $company['id'] ), ARRAY_A );
		if ( $r ) {
			$p = self::decode( $r['json'] );
			if ( is_array( $p ) ) {
				return array( 'profile' => $p, 'source' => 'guardado', 'updated_at' => $r['updated_at'], 'updated_by' => (int) $r['updated_by'] );
			}
		}
		$def = null;
		if ( EngineBridge::has( 'profiles' ) ) {
			$all = EngineBridge::default_profiles();
			$def = isset( $all[ $company['code'] ] ) && is_array( $all[ $company['code'] ] ) ? $all[ $company['code'] ] : null;
		}
		return array( 'profile' => $def, 'source' => null === $def ? 'ninguno' : 'predeterminado', 'updated_at' => null, 'updated_by' => 0 );
	}

	/** Guarda el perfil (null = borrar y volver al predeterminado). */
	public static function save_profile( int $company_id, ?array $profile, int $uid ): void {
		global $wpdb;
		$t = Schema::t( 'profiles' );
		$wpdb->delete( $t, array( 'company_id' => $company_id ), array( '%d' ) );
		if ( null !== $profile ) {
			$wpdb->insert(
				$t,
				array(
					'company_id' => $company_id,
					'json'       => self::json( $profile ),
					'updated_by' => $uid,
					'updated_at' => Schema::now(),
				),
				array( '%d', '%s', '%d', '%s' )
			);
		}
	}

	/* =====================================================================
	 * Versiones (ciclos): ppto_2026, real_2026, ppto_2027_v1, ppto_2027_v2…
	 * ===================================================================*/

	public static function valid_key( string $key ): bool {
		return (bool) preg_match( '/^[a-z0-9_]{1,30}$/', $key );
	}

	/** [año, tipo (ppto|real|otro), n de versión (0 si no tiene)]. */
	public static function parse_key( string $key ): array {
		if ( preg_match( '/^(ppto|real)_(\d{4})(?:_v(\d+))?$/', $key, $m ) ) {
			return array( (int) $m[2], $m[1], isset( $m[3] ) ? (int) $m[3] : 0 );
		}
		return array( preg_match( '/(\d{4})/', $key, $y ) ? (int) $y[1] : 0, 'otro', 0 );
	}

	public static function label_for( string $key ): string {
		[ $y, $kind, $n ] = self::parse_key( $key );
		if ( 'real' === $kind ) {
			return 'Real ' . $y;
		}
		if ( 'ppto' === $kind ) {
			return 'Presupuesto ' . $y . ( $n ? ' v' . $n : '' );
		}
		return $key;
	}

	/** Orden cronológico: ppto_Y (0) < ppto_Y_vN (N) < real_Y (50). */
	public static function rank_for( string $key ): int {
		[ $y, $kind, $n ] = self::parse_key( $key );
		return $y * 100 + ( 'real' === $kind ? 50 : ( 'ppto' === $kind ? min( 49, $n ) : 60 ) );
	}

	public static function version_from_row( array $r ): array {
		return array(
			'id'         => (int) $r['id'],
			'key'        => (string) $r['version_key'],
			'label'      => '' !== (string) $r['label'] ? (string) $r['label'] : self::label_for( (string) $r['version_key'] ),
			'year'       => (int) $r['year'],
			'status'     => (string) $r['status'],
			'created_at' => (string) $r['created_at'],
		);
	}

	/** Versiones de la más reciente a la más antigua. */
	public static function versions(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::t( 'versions' ) . ' ORDER BY sort DESC, id DESC', ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'version_from_row' ), (array) $rows );
	}

	public static function version( string $key ): ?array {
		global $wpdb;
		if ( ! self::valid_key( $key ) ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'versions' ) . ' WHERE version_key = %s', $key ), ARRAY_A );
		return $r ? self::version_from_row( $r ) : null;
	}

	/** Crea la versión si no existe; devuelve la fila. */
	public static function ensure_version( string $key, string $label = '', int $year = 0 ): ?array {
		global $wpdb;
		if ( ! self::valid_key( $key ) ) {
			return null;
		}
		$v = self::version( $key );
		if ( $v ) {
			return $v;
		}
		[ $y ] = self::parse_key( $key );
		$wpdb->insert(
			Schema::t( 'versions' ),
			array(
				'version_key' => $key,
				'label'       => '' !== $label ? $label : self::label_for( $key ),
				'year'        => $year ? $year : $y,
				'status'      => 'abierto',
				'sort'        => self::rank_for( $key ),
				'created_at'  => Schema::now(),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s' )
		);
		return self::version( $key );
	}

	public static function set_version_status( string $key, string $status ): void {
		global $wpdb;
		$wpdb->update( Schema::t( 'versions' ), array( 'status' => $status ), array( 'version_key' => $key ), array( '%s' ), array( '%s' ) );
	}

	/** Versión por defecto: la más reciente abierta, o la más reciente. */
	public static function default_version(): ?array {
		$all = self::versions();
		foreach ( $all as $v ) {
			if ( 'abierto' === $v['status'] ) {
				return $v;
			}
		}
		return $all[0] ?? null;
	}

	/**
	 * Versión base para comparar (§11): ppto_Y_vN → ppto_Y_v(N−1); ppto_Y_v1 o ppto_Y → real_(Y−1);
	 * real_Y → ppto_Y (o la última ppto_Y_vN). Si no existe, la versión inmediatamente anterior.
	 */
	public static function base_version( string $key ): ?array {
		[ $y, $kind, $n ] = self::parse_key( $key );
		$cands = array();
		if ( 'ppto' === $kind && $n > 1 ) {
			$cands[] = 'ppto_' . $y . '_v' . ( $n - 1 );
		}
		if ( 'ppto' === $kind ) {
			$cands[] = 'real_' . ( $y - 1 );
		}
		if ( 'real' === $kind ) {
			$cands[] = 'ppto_' . $y;
			for ( $i = 9; $i >= 1; $i-- ) {
				$cands[] = 'ppto_' . $y . '_v' . $i;
			}
		}
		foreach ( $cands as $c ) {
			$v = self::version( $c );
			if ( $v ) {
				return $v;
			}
		}
		$rank = self::rank_for( $key );
		foreach ( self::versions() as $v ) {
			if ( self::rank_for( $v['key'] ) < $rank ) {
				return $v;
			}
		}
		return null;
	}

	/* =====================================================================
	 * Tasas mensuales (moneda local por 1 USD; mes 0 = saldo inicial)
	 * ===================================================================*/

	private static function rate_from_row( array $r ): array {
		return array(
			'budget'      => self::f( $r['budget'] ),
			'avg'         => self::f( $r['avg'] ),
			'close'       => self::f( $r['close'] ),
			'status'      => (string) $r['status'],
			'approved_by' => (string) $r['approved_by'],
			'approved_at' => (string) $r['approved_at'],
		);
	}

	public static function rates( string $key ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'rates' ) . ' WHERE version_key = %s ORDER BY currency ASC, month ASC', $key ), ARRAY_A );
		return array_map( fn( $r ) => array( 'currency' => (string) $r['currency'], 'month' => (int) $r['month'] ) + self::rate_from_row( $r ), $rows );
	}

	public static function save_rate( string $key, string $cur, int $month, ?float $budget, ?float $avg, ?float $close, string $status, string $by = '', string $at = '' ): void {
		global $wpdb;
		$t   = Schema::t( 'rates' );
		$row = array(
			'budget'      => $budget,
			'avg'         => $avg,
			'close'       => $close,
			'status'      => $status,
			'approved_by' => mb_substr( $by, 0, 190 ),
			'approved_at' => substr( $at, 0, 30 ),
		);
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE version_key = %s AND currency = %s AND month = %d", $key, $cur, $month ) ); // phpcs:ignore
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $t, $row + array( 'version_key' => $key, 'currency' => $cur, 'month' => $month ) );
		}
		self::$corporate_cache = null;
	}

	public static function approve_rates( string $key, string $by ): int {
		global $wpdb;
		self::$corporate_cache = null;
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::t( 'rates' ) . " SET status = 'aprobada', approved_by = %s, approved_at = %s WHERE version_key = %s", mb_substr( $by, 0, 190 ), gmdate( 'Y-m-d' ), $key ) );
	}

	/** Monedas de las empresas dadas a las que les falta algún mes 1..12 aprobado (avg y close > 0). */
	public static function missing_rates( string $key, array $companies ): array {
		$ok = array();
		foreach ( self::rates( $key ) as $r ) {
			if ( $r['month'] >= 1 && 'aprobada' === $r['status'] && $r['avg'] > 0 && $r['close'] > 0 ) {
				$ok[ $r['currency'] ][ $r['month'] ] = true;
			}
		}
		return array_values( array_filter( self::currencies( $companies ), fn( $cur ) => count( $ok[ $cur ] ?? array() ) < 12 ) );
	}

	/* =====================================================================
	 * Catálogo y maestros
	 * ===================================================================*/

	public static function catalog(): array {
		return array_values( self::corporate()['catalog'] );
	}

	/* =====================================================================
	 * Subidas
	 * ===================================================================*/

	public static function upload_from_row( array $r ): array {
		return array(
			'id'          => (int) $r['id'],
			'version'     => (string) $r['version_key'],
			'company_id'  => (int) $r['company_id'],
			'file_name'   => (string) $r['file_name'],
			'sha256'      => (string) $r['sha256'],
			'size'        => (int) $r['size'],
			'uploaded_by' => (int) $r['uploaded_by'],
			'uploader'    => Log::user_name( (int) $r['uploaded_by'] ),
			'uploaded_at' => (string) $r['uploaded_at'],
			'status'      => (string) $r['status'],
			'approved_by' => (int) $r['approved_by'],
			'approved_at' => $r['approved_at'],
			'sent_at'     => $r['sent_at'],
			'criticals'   => (int) $r['criticals'],
			'warnings'    => (int) $r['warnings'],
			'superseded'  => (int) $r['superseded'],
		);
	}

	public static function upload( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::UPLOAD_COLS . ' FROM ' . Schema::t( 'uploads' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ? self::upload_from_row( $r ) : null;
	}

	public static function upload_blob( int $id ): ?string {
		global $wpdb;
		$b = $wpdb->get_var( $wpdb->prepare( 'SELECT file_blob FROM ' . Schema::t( 'uploads' ) . ' WHERE id = %d', $id ) );
		return null === $b ? null : (string) $b;
	}

	public static function upload_dataset( int $id ): ?array {
		global $wpdb;
		$s  = $wpdb->get_var( $wpdb->prepare( 'SELECT dataset FROM ' . Schema::t( 'uploads' ) . ' WHERE id = %d', $id ) );
		$ds = self::decode( null === $s ? null : (string) $s );
		return is_array( $ds ) ? $ds : null;
	}

	public static function current_upload( string $key, int $company_id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::UPLOAD_COLS . ' FROM ' . Schema::t( 'uploads' ) . ' WHERE version_key = %s AND company_id = %d AND superseded = 0 ORDER BY id DESC LIMIT 1', $key, $company_id ), ARRAY_A );
		return $r ? self::upload_from_row( $r ) : null;
	}

	/** Subidas vigentes de la versión: company_id => upload. */
	public static function current_uploads( string $key ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::UPLOAD_COLS . ' FROM ' . Schema::t( 'uploads' ) . ' WHERE version_key = %s AND superseded = 0 ORDER BY id ASC', $key ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['company_id'] ] = self::upload_from_row( $r );
		}
		return $out;
	}

	/** Historial de subidas (más reciente primero). Filtros opcionales. */
	public static function uploads( string $key = '', int $company_id = 0 ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( '' !== $key ) {
			$where[] = 'version_key = %s';
			$args[]  = $key;
		}
		if ( $company_id ) {
			$where[] = 'company_id = %d';
			$args[]  = $company_id;
		}
		$sql  = 'SELECT ' . self::UPLOAD_COLS . ' FROM ' . Schema::t( 'uploads' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC';
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'upload_from_row' ), (array) $rows );
	}

	/** Guarda una subida nueva y marca como reemplazadas las anteriores de la misma empresa y versión. */
	public static function insert_upload( array $d ): int {
		global $wpdb;
		$t  = Schema::t( 'uploads' );
		$ok = $wpdb->insert(
			$t,
			array(
				'version_key' => (string) $d['version'],
				'company_id'  => (int) $d['company_id'],
				'file_name'   => (string) $d['file_name'],
				'file_blob'   => (string) $d['file_blob'],
				'sha256'      => (string) $d['sha256'],
				'size'        => (int) $d['size'],
				'uploaded_by' => (int) $d['uploaded_by'],
				'uploaded_at' => Schema::now(),
				'status'      => (string) $d['status'],
				'dataset'     => self::json( $d['dataset'] ),
				'criticals'   => (int) $d['criticals'],
				'warnings'    => (int) $d['warnings'],
				'superseded'  => 0,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d' )
		);
		$id = $ok ? (int) $wpdb->insert_id : 0;
		if ( $id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET superseded = 1 WHERE version_key = %s AND company_id = %d AND id <> %d", (string) $d['version'], (int) $d['company_id'], $id ) ); // phpcs:ignore
		}
		return $id;
	}

	public static function update_upload( int $id, array $fields ): void {
		global $wpdb;
		$allowed = array_intersect_key( $fields, array_flip( array( 'status', 'approved_by', 'approved_at', 'sent_at', 'criticals', 'warnings' ) ) );
		if ( $allowed ) {
			$wpdb->update( Schema::t( 'uploads' ), $allowed, array( 'id' => $id ) );
		}
	}

	/* =====================================================================
	 * Hallazgos (issues §7)
	 * ===================================================================*/

	private static function codes_by_id(): array {
		$out = array();
		foreach ( self::companies() as $c ) {
			$out[ $c['id'] ] = $c['code'];
		}
		return $out;
	}

	public static function issue_from_row( array $r, array $codes = array() ): array {
		$cid = (int) $r['company_id'];
		return array(
			'id'          => (int) $r['id'],
			'upload_id'   => null === $r['upload_id'] ? null : (int) $r['upload_id'],
			'version'     => (string) $r['version_key'],
			'company_id'  => $cid,
			'company'     => $codes[ $cid ] ?? '',
			'code'        => (string) $r['code'],
			'severity'    => (string) $r['severity'],
			'category'    => (string) $r['category'],
			'sheet'       => (string) $r['sheet'],
			'row'         => null === $r['row_num'] ? null : (int) $r['row_num'],
			'col'         => $r['col'],
			'month'       => null === $r['month'] ? null : (int) $r['month'],
			'concept'     => $r['concept'],
			'message'     => (string) $r['message'],
			'actual'      => self::f( $r['actual'] ),
			'expected'    => self::f( $r['expected'] ),
			'impact_usd'  => self::f( $r['impact_usd'] ),
			'status'      => (string) $r['status'],
			'explanation' => (string) $r['explanation'],
			'created_at'  => (string) $r['created_at'],
		);
	}

	/** Guarda Issues del motor (§7). $company_id = 0 → se resuelve por el código de cada issue. */
	public static function insert_issues( ?int $upload_id, string $key, int $company_id, array $issues ): void {
		global $wpdb;
		$t     = Schema::t( 'issues' );
		$codes = $company_id ? array() : array_flip( self::codes_by_id() );
		foreach ( $issues as $i ) {
			$code = substr( (string) ( $i['code'] ?? 'DATA_EMPTY' ), 0, 40 );
			$cid  = $company_id ? $company_id : (int) ( $codes[ strtoupper( (string) ( $i['company'] ?? '' ) ) ] ?? 0 );
			$cat  = (string) ( $i['category'] ?? '' );
			$wpdb->insert(
				$t,
				array(
					'upload_id'   => $upload_id,
					'version_key' => $key,
					'company_id'  => $cid,
					'code'        => $code,
					'severity'    => 'critico' === ( $i['severity'] ?? '' ) ? 'critico' : 'aviso',
					'category'    => substr( isset( self::CATEGORIES[ $cat ] ) ? $cat : self::category_for( $code ), 0, 30 ),
					'sheet'       => mb_substr( (string) ( $i['sheet'] ?? '' ), 0, 60 ),
					'row_num'     => isset( $i['row'] ) && is_numeric( $i['row'] ) ? (int) $i['row'] : null,
					'col'         => isset( $i['col'] ) && '' !== (string) $i['col'] ? substr( (string) $i['col'], 0, 10 ) : null,
					'month'       => isset( $i['month'] ) && is_numeric( $i['month'] ) ? max( 0, min( 12, (int) $i['month'] ) ) : null,
					'concept'     => isset( $i['concept'] ) && '' !== (string) $i['concept'] ? mb_substr( (string) $i['concept'], 0, 60 ) : null,
					'message'     => mb_substr( (string) ( $i['message'] ?? '' ), 0, 2000 ),
					'actual'      => self::f( $i['actual'] ?? null ),
					'expected'    => self::f( $i['expected'] ?? null ),
					'impact_usd'  => self::f( $i['impact_usd'] ?? null ),
					'status'      => 'abierta',
					'explanation' => null,
					'created_at'  => Schema::now(),
				)
			);
		}
	}

	public static function issue( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'issues' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ? self::issue_from_row( $r, self::codes_by_id() ) : null;
	}

	public static function issues_for_upload( int $upload_id ): array {
		global $wpdb;
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'issues' ) . " WHERE upload_id = %d ORDER BY FIELD(severity,'critico','aviso'), id ASC", $upload_id ), ARRAY_A );
		$codes = self::codes_by_id();
		return array_map( fn( $r ) => self::issue_from_row( $r, $codes ), $rows );
	}

	public static function update_issue( int $id, string $status, string $explanation ): void {
		global $wpdb;
		$wpdb->update( Schema::t( 'issues' ), array( 'status' => $status, 'explanation' => $explanation ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
	}

	/**
	 * Hallazgos vigentes de la versión: los de las subidas vigentes más los intercompañía (upload_id NULL).
	 * Filtros opcionales: solo abiertos, empresas, categoría y severidad.
	 */
	public static function version_issues( string $key, bool $only_open = true, ?array $company_ids = null, string $category = '', string $severity = '' ): array {
		global $wpdb;
		$I    = Schema::t( 'issues' );
		$U    = Schema::t( 'uploads' );
		$sql  = "SELECT i.* FROM $I i LEFT JOIN $U u ON u.id = i.upload_id WHERE i.version_key = %s AND ( i.upload_id IS NULL OR u.superseded = 0 )";
		$args = array( $key );
		if ( $only_open ) {
			$sql .= " AND i.status = 'abierta'";
		}
		if ( null !== $company_ids ) {
			$sql .= ' AND i.company_id IN (' . self::ids_in( $company_ids ) . ')';
		}
		if ( '' !== $category ) {
			$sql   .= ' AND i.category = %s';
			$args[] = $category;
		}
		if ( '' !== $severity ) {
			$sql   .= ' AND i.severity = %s';
			$args[] = $severity;
		}
		$sql  .= " ORDER BY FIELD(i.severity,'critico','aviso'), i.company_id, i.id";
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore -- preparado arriba.
		$codes = self::codes_by_id();
		return array_map( fn( $r ) => self::issue_from_row( $r, $codes ), $rows );
	}

	/** Reemplaza los hallazgos intercompañía (upload_id NULL) de la versión. */
	public static function replace_ic_issues( string $key, array $issues ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::t( 'issues' ) . ' WHERE version_key = %s AND upload_id IS NULL', $key ) );
		self::insert_issues( null, $key, 0, $issues );
	}

	/* =====================================================================
	 * Resultados
	 * ===================================================================*/

	public static function save_result( string $key, array $data, int $uid ): int {
		global $wpdb;
		$wpdb->insert(
			Schema::t( 'results' ),
			array(
				'version_key' => $key,
				'created_at'  => Schema::now(),
				'created_by'  => $uid,
				'data'        => self::json( $data ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	private static function result_from_row( ?array $r ): ?array {
		if ( ! $r ) {
			return null;
		}
		return array(
			'id'         => (int) $r['id'],
			'version'    => (string) $r['version_key'],
			'created_at' => (string) $r['created_at'],
			'created_by' => (int) $r['created_by'],
			'data'       => (array) self::decode( $r['data'] ),
		);
	}

	public static function result( int $id ): ?array {
		global $wpdb;
		return self::result_from_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'results' ) . ' WHERE id = %d', $id ), ARRAY_A ) );
	}

	public static function latest_result( string $key ): ?array {
		global $wpdb;
		return self::result_from_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'results' ) . ' WHERE version_key = %s ORDER BY id DESC LIMIT 1', $key ), ARRAY_A ) );
	}

	/** Solo id y fecha (sin decodificar el JSON). */
	public static function latest_result_head( string $key ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT id, created_at FROM ' . Schema::t( 'results' ) . ' WHERE version_key = %s ORDER BY id DESC LIMIT 1', $key ), ARRAY_A );
		return $r ? array( 'id' => (int) $r['id'], 'created_at' => (string) $r['created_at'] ) : null;
	}

	/* =====================================================================
	 * Acciones
	 * ===================================================================*/

	public static function action_from_row( array $r ): array {
		return array(
			'id'         => (int) $r['id'],
			'version'    => (string) $r['version_key'],
			'title'      => (string) $r['title'],
			'owner'      => (string) $r['owner'],
			'due_date'   => $r['due_date'],
			'status'     => (string) $r['status'],
			'effect'     => (string) $r['effect'],
			'created_by' => (int) $r['created_by'],
			'creator'    => Log::user_name( (int) $r['created_by'] ),
			'created_at' => (string) $r['created_at'],
		);
	}

	public static function actions( string $key = '', bool $open_only = false ): array {
		global $wpdb;
		$t     = Schema::t( 'actions' );
		$where = $open_only ? " AND status <> 'hecha'" : '';
		if ( '' !== $key ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE version_key = %s$where ORDER BY (due_date IS NULL), due_date ASC, id DESC", $key ), ARRAY_A ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM $t WHERE 1=1$where ORDER BY (due_date IS NULL), due_date ASC, id DESC", ARRAY_A ); // phpcs:ignore
		}
		return array_map( array( __CLASS__, 'action_from_row' ), (array) $rows );
	}

	public static function action( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'actions' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ? self::action_from_row( $r ) : null;
	}

	public static function save_action( array $d, int $id = 0 ): int {
		global $wpdb;
		$t = Schema::t( 'actions' );
		if ( $id ) {
			if ( $d ) {
				$wpdb->update( $t, $d, array( 'id' => $id ) );
			}
			return $id;
		}
		$d['created_at'] = Schema::now();
		$wpdb->insert( $t, $d );
		return (int) $wpdb->insert_id;
	}

	/* =====================================================================
	 * Entradas del motor
	 * ===================================================================*/

	/**
	 * Datasets de las subidas vigentes de la versión.
	 *
	 * @param array|null $statuses estados admitidos (null = cualquiera).
	 * @return array [datasets code => Dataset, uploads code => upload]
	 */
	public static function datasets( string $key, ?array $statuses = array( 'enviado' ) ): array {
		$datasets = array();
		$uploads  = array();
		$current  = self::current_uploads( $key );
		foreach ( self::companies() as $c ) {
			$u = $current[ $c['id'] ] ?? null;
			if ( ! $u || ( null !== $statuses && ! in_array( $u['status'], $statuses, true ) ) ) {
				continue;
			}
			$ds = self::upload_dataset( $u['id'] );
			if ( $ds ) {
				$datasets[ $c['code'] ] = $ds;
				$uploads[ $c['code'] ]  = $u;
			}
		}
		return array( $datasets, $uploads );
	}

	/** Datasets de un resultado guardado (por id de subida; siguen disponibles aunque luego se reemplacen). */
	public static function result_datasets( array $result_row ): array {
		$out = array();
		foreach ( (array) ( $result_row['data']['uploads'] ?? array() ) as $code => $u ) {
			$ds = self::upload_dataset( (int) ( $u['id'] ?? 0 ) );
			if ( $ds ) {
				$out[ $code ] = $ds;
			}
		}
		return $out;
	}

	/**
	 * Contexto para EngineBridge::validate.
	 * CONTRATO: v2 no lo define; ver EngineBridge::validate.
	 */
	public static function validator_ctx( array $company, string $key, ?array $profile ): array {
		$corp   = self::corporate();
		$others = array();
		foreach ( self::versions() as $v ) {
			if ( $v['key'] === $key ) {
				continue;
			}
			$u = self::current_upload( $v['key'], $company['id'] );
			if ( $u ) {
				$ds = self::upload_dataset( $u['id'] );
				if ( $ds ) {
					$others[ $v['key'] ] = $ds;
				}
			}
		}
		return array(
			'company'   => $corp['companies'][ $company['code'] ] ?? $company,
			'corporate' => $corp,
			'version'   => $key,
			'profile'   => $profile,
			'others'    => $others,
		);
	}
}
