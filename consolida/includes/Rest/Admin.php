<?php
namespace Consolida\Rest;

use Consolida\Demo;
use Consolida\EngineBridge;
use Consolida\Log;
use Consolida\Roles;
use Consolida\Store;

/** Sesión, datos fijos, inicio, corporativo, empresas, perfiles, versiones, tasas, bitácora y demo. */
class Admin extends Base {
	const SCALES = array( 'unidades', 'miles' );

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		self::route( '/me', 'GET', 'me' );
		self::route( '/meta', 'GET', 'meta' );
		self::route( '/home', 'GET', 'home' );
		self::route( '/corporate/import', 'POST', 'corporate_import', 'corp' );
		self::route( '/companies', 'GET', 'companies', 'corp' );
		self::route( '/companies', 'POST', 'company_save', 'corp' );
		self::route( '/companies/(?P<id>\d+)', 'POST', 'company_save', 'corp' );
		self::route( '/users', 'GET', 'users', 'corp' );
		self::route( '/profiles', 'GET', 'profiles', 'corp' );
		self::route( '/profiles/(?P<company>[A-Za-z0-9_-]+)', 'POST', 'profile_save', 'corp' );
		self::route( '/versions', 'GET', 'versions' );
		self::route( '/versions', 'POST', 'version_create', 'corp' );
		self::route( '/versions/(?P<key>[a-z0-9_]+)', 'POST', 'version_update', 'corp' );
		self::route( '/rates', 'GET', 'rates' );
		self::route( '/rates', 'POST', 'rates_save', 'corp' );
		self::route( '/log', 'GET', 'log', 'corp' );
		self::route( '/demo/load', 'POST', 'demo_load', 'admin' );
		self::route( '/demo/reset', 'POST', 'demo_reset', 'admin' );
		self::route( '/demo/load-one', 'POST', 'demo_load_one', 'country_corp' );
	}

	/* =====================================================================
	 * Sesión y datos fijos
	 * ===================================================================*/

	public static function me() {
		$u    = wp_get_current_user();
		$caps = Roles::caps();
		$ids  = ( $caps['corp'] || $caps['admin'] ) ? array_map( fn( $c ) => $c['id'], Store::companies() ) : Roles::assigned_ids();
		return array(
			'id'        => (int) $u->ID,
			'name'      => $u->display_name,
			'roles'     => array_values( (array) $u->roles ),
			'caps'      => $caps,
			'companies' => array_values( $ids ),
			'seesAll'   => null === Roles::visible_company_ids(),
		);
	}

	/** Textos cortos por código de hallazgo (los del motor, si los publica, tienen prioridad). */
	public static function issue_codes(): array {
		$base = array(
			'FMT_SCALE_DECL'  => 'La portada declara una escala distinta a la del país',
			'FMT_SCALE_JUMP'  => 'Salto de escala (≥ 500× o ≤ 1/500)',
			'FMT_TEXT_NUMBER' => 'Números guardados como texto',
			'FMT_DATE'        => 'Fecha o periodo fuera de la versión, repetido o faltante',
			'FMT_READ'        => 'No se pudo leer el archivo',
			'CAT_UNKNOWN'     => 'Etiqueta o código sin mapeo al catálogo',
			'CAT_PRODUCT'     => 'Producto fuera del maestro',
			'CAT_CHANNEL'     => 'Canal fuera del maestro',
			'DATA_EMPTY'      => 'Celda vacía donde debe haber valor',
			'DATA_DUP'        => 'Concepto repetido en el mismo mes',
			'CALC_VOLPRICE'   => 'Ventas netas distintas de volumen × precio',
			'CALC_SPLIT'      => 'Ventas netas distintas de terceros + intercompañía',
			'CALC_DETAIL'     => 'El detalle de ventas no cuadra con resultados',
			'CALC_TOTAL'      => 'Fila de total distinta de la suma del detalle',
			'CALC_BS'         => 'Balance descuadrado',
			'CALC_DEPR'       => 'La depreciación acumulada no cuadra con la del mes',
			'SIGN'            => 'Signo contrario al esperado',
			'FX_USED'         => 'Tipo de cambio usado distinto del oficial',
			'FX_IC_CURRENCY'  => 'Intercompañía pactada en moneda distinta de USD',
			'KPI_COPY'        => 'Indicador reportado distinto del calculado',
			'KPI_WACC'        => 'WACC reportado distinto del oficial',
			'IC_MISMATCH'     => 'La operación intercompañía no cuadra',
			'IC_MISSING'      => 'Falta el otro lado de la operación intercompañía',
			'IC_TIMING'       => 'Diferencia de tiempo en intercompañía',
			'IC_PL_TIE'       => 'Ventas intercompañía de resultados no cuadran con la hoja intercompañía',
			'IC_BALANCE'      => 'Saldos intercompañía no cuadran entre países',
			'UPLOAD_MISMATCH' => 'El archivo es de otra empresa o versión',
		);
		$engine = class_exists( \Consolida\Engine\Validator::class ) && defined( '\Consolida\Engine\Validator::CODES' ) ? (array) \Consolida\Engine\Validator::CODES : array();
		return array_merge( $base, $engine );
	}

	public static function meta() {
		$corp = Store::corporate();
		$out  = array(
			'catalog'        => array_values( $corp['catalog'] ),
			'products'       => $corp['products'],
			'channels'       => $corp['channels'],
			'companies'      => Store::companies( Roles::visible_company_ids() ),
			'versions'       => Store::versions(),
			'categories'     => Store::CATEGORIES,
			'issueCodes'     => self::issue_codes(),
			'uploadStatuses' => array(
				'rechazado'  => 'Rechazado',
				'con_avisos' => 'Con avisos',
				'validado'   => 'Validado',
				'aprobado'   => 'Aprobado',
				'enviado'    => 'Enviado',
			),
			'maxUploadMb'    => (int) ( Data::MAX_BYTES / 1048576 ),
			'hasCorporate'   => Store::has_corporate(),
			'corporateFile'  => get_option( 'csl_corporate_meta', null ),
		);
		if ( Roles::is_corp() ) {
			$out['engine'] = EngineBridge::status();
		}
		return $out;
	}

	/** Inicio por rol: estado de cada empresa visible en la versión. */
	public static function home( \WP_REST_Request $r ) {
		$version = Store::default_version();
		if ( '' !== (string) $r->get_param( 'version' ) ) {
			$version = self::version_param( $r );
			if ( is_wp_error( $version ) ) {
				return $version;
			}
		}
		$list  = array();
		$score = null;
		$last  = null;
		if ( $version ) {
			$current = Store::current_uploads( $version['key'] );
			foreach ( Store::companies( Roles::visible_company_ids() ) as $c ) {
				$u      = $current[ $c['id'] ] ?? null;
				$list[] = array(
					'id'       => $c['id'],
					'code'     => $c['code'],
					'name'     => $c['name'],
					'country'  => $c['country'],
					'currency' => $c['currency'],
					'upload'   => $u ? array(
						'id'          => $u['id'],
						'status'      => $u['status'],
						'criticals'   => $u['criticals'],
						'warnings'    => $u['warnings'],
						'uploaded_at' => $u['uploaded_at'],
						'sent_at'     => $u['sent_at'],
					) : null,
				);
			}
			if ( Roles::is_corp() ) {
				// CONTRATO: v2 quita la lista de errores esperados; el marcador muestra hallazgos de la versión.
				$score = Group::findings_counts( Store::version_issues( $version['key'], false ) );
			}
			if ( Roles::is_corp() || Roles::is_board() ) {
				$last = Store::latest_result_head( $version['key'] );
			}
		}
		return array(
			'version'      => $version,
			'hasCorporate' => Store::has_corporate(),
			'companies'    => $list,
			'scoreboard'   => $score,
			'lastResult'   => $last,
		);
	}

	/* =====================================================================
	 * Corporativo
	 * ===================================================================*/

	/**
	 * Importa Grupo_Corporativo.xlsx: subido en `file` (multipart) o, con `source=server`, desde la carpeta de datos
	 * del servidor (Demo::DATA_DIR/2_Grupo/Grupo_Corporativo.xlsx).
	 */
	public static function corporate_import( \WP_REST_Request $r ) {
		$files = $r->get_file_params();
		$f     = $files['file'] ?? null;
		if ( is_array( $f ) && UPLOAD_ERR_OK === ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) && ! empty( $f['tmp_name'] ) && is_uploaded_file( $f['tmp_name'] ) ) {
			if ( (int) $f['size'] > Data::MAX_BYTES ) {
				return self::err( 'El archivo pesa más de 10 MB.', 413 );
			}
			$bytes = (string) file_get_contents( $f['tmp_name'] ); // phpcs:ignore
			$name  = (string) ( $f['name'] ?? 'Grupo_Corporativo.xlsx' );
		} elseif ( 'server' === self::str( $r, 'source', 20 ) ) {
			$path = Demo::corporate_path();
			if ( ! $path ) {
				return self::err( 'No se encontró 2_Grupo/Grupo_Corporativo.xlsx en la carpeta de datos del servidor.', 404, 'csl_not_found' );
			}
			$bytes = (string) file_get_contents( $path ); // phpcs:ignore
			$name  = basename( $path );
		} else {
			return self::err( 'Sube el archivo corporativo (.xlsx) o usa source=server.' );
		}
		return self::import_corporate_bytes( $bytes, $name );
	}

	/** Flujo único de importación del corporativo (API y demo). */
	public static function import_corporate_bytes( string $bytes, string $file_name ) {
		$file_name = mb_substr( sanitize_file_name( $file_name ), 0, 190 );
		if ( ! preg_match( '/\.xlsx$/i', $file_name ) || strlen( $bytes ) < 4 || substr( $bytes, 0, 4 ) !== Data::ZIP_SIG ) {
			return self::err( 'El archivo corporativo debe ser un Excel .xlsx.', 415 );
		}
		$corp = self::engine(
			fn() => Data::with_temp( $bytes, $file_name, fn( $path ) => EngineBridge::corporate( $path ) ),
			'No se pudo leer el archivo corporativo.'
		);
		if ( is_wp_error( $corp ) ) {
			return $corp;
		}
		if ( empty( $corp['companies'] ) ) {
			return self::err( 'El archivo corporativo no trae países (hoja parametros_paises).', 422 );
		}
		$n                   = Store::import_corporate( $corp );
		$n['versions_total'] = count( Store::versions() );
		// Perfiles: se siembran los predeterminados solo donde no haya uno guardado (Store::profile ya cae al
		// predeterminado al leer, así que aquí no hace falta escribir nada).
		$meta = array(
			'file'        => $file_name,
			'sha256'      => hash( 'sha256', $bytes ),
			'imported_at' => gmdate( 'Y-m-d H:i:s' ),
			'imported_by' => get_current_user_id(),
		);
		update_option( 'csl_corporate_meta', $meta, false );
		Log::write( 'importar_corporativo', 'corporate', 0, array( 'file' => $file_name, 'counts' => $n ) );
		return array(
			'ok'     => true,
			'file'   => $meta,
			'counts' => $n,
		);
	}

	/* =====================================================================
	 * Empresas y usuarios
	 * ===================================================================*/

	private static function company_out( array $c ): array {
		$c['users'] = array_map(
			fn( $uid ) => array( 'id' => $uid, 'name' => Log::user_name( $uid ) ),
			Store::company_user_ids( $c['id'] )
		);
		return $c;
	}

	public static function companies() {
		return array_map( array( __CLASS__, 'company_out' ), Store::companies() );
	}

	/** Fracción 0–1; acepta porcentaje (25 → 0,25). */
	private static function fraction( $v ): ?float {
		$n = self::num( $v );
		if ( null === $n ) {
			return null;
		}
		if ( $n > 1 ) {
			$n = $n / 100;
		}
		return ( $n >= 0 && $n <= 1 ) ? $n : null;
	}

	public static function company_save( \WP_REST_Request $r ) {
		$id  = self::int( $r, 'id' );
		$old = $id ? Store::company( $id ) : null;
		if ( $id && ! $old ) {
			return self::not_found( 'La empresa' );
		}
		$d   = array();
		$has = fn( $k ) => null !== $r->get_param( $k );
		if ( $has( 'code' ) || ! $id ) {
			$code = strtoupper( self::str( $r, 'code', 20 ) );
			if ( ! preg_match( '/^[A-Z0-9_-]{1,20}$/', $code ) ) {
				return self::err( 'El código solo admite letras, números, guion y guion bajo (máximo 20).' );
			}
			$dup = Store::company_by_code( $code );
			if ( $dup && $dup['id'] !== $id ) {
				return self::err( 'Ya existe una empresa con el código ' . $code . '.', 409 );
			}
			$d['code'] = $code;
		}
		if ( $has( 'name' ) || ! $id ) {
			$d['name'] = self::str( $r, 'name' );
			if ( '' === $d['name'] ) {
				return self::err( 'Escribe el nombre de la empresa.' );
			}
		}
		if ( $has( 'number' ) ) {
			$d['number'] = self::int( $r, 'number' );
		}
		foreach ( array( 'country' => 100, 'data_owner' => 190, 'approver' => 190 ) as $k => $max ) {
			if ( $has( $k ) ) {
				$d[ $k ] = self::str( $r, $k, $max );
			}
		}
		if ( $has( 'minority_partner' ) ) {
			$mp                    = self::str( $r, 'minority_partner' );
			$d['minority_partner'] = '' === $mp ? null : $mp;
		}
		if ( $has( 'currency' ) || ! $id ) {
			$cur = strtoupper( self::str( $r, 'currency', 3 ) );
			if ( ! preg_match( '/^[A-Z]{3}$/', $cur ) ) {
				return self::err( 'La moneda debe ser un código de 3 letras (por ejemplo GTQ).' );
			}
			$d['currency'] = $cur;
		}
		if ( $has( 'scale' ) ) {
			$sc = self::str( $r, 'scale', 20 );
			if ( ! in_array( $sc, self::SCALES, true ) ) {
				return self::err( 'La escala debe ser unidades o miles.' );
			}
			$d['scale'] = $sc;
		}
		$labels = array( 'ownership' => 'La participación del grupo', 'minority' => 'La participación minoritaria', 'tax' => 'La tasa de impuesto', 'wacc' => 'El WACC' );
		foreach ( $labels as $k => $label ) {
			if ( $has( $k ) ) {
				$f = self::fraction( $r->get_param( $k ) );
				if ( null === $f ) {
					return self::err( $label . ' debe estar entre 0 y 100 %.' );
				}
				$d[ $k ] = $f;
			}
		}
		// Si cambia solo una de las dos participaciones, la otra se ajusta para sumar 100 %.
		if ( isset( $d['ownership'] ) && ! isset( $d['minority'] ) ) {
			$d['minority'] = round( 1 - $d['ownership'], 6 );
		} elseif ( isset( $d['minority'] ) && ! isset( $d['ownership'] ) ) {
			$d['ownership'] = round( 1 - $d['minority'], 6 );
		}
		$new_id = Store::save_company( $d, $id );
		if ( ! $new_id ) {
			return self::err( 'No se pudo guardar la empresa.', 500 );
		}
		if ( is_array( $r->get_param( 'users' ) ) ) {
			Store::set_company_users( $new_id, array_map( 'intval', (array) $r->get_param( 'users' ) ) );
		}
		Log::write( $id ? 'editar' : 'crear', 'company', $new_id, array( 'code' => $d['code'] ?? ( $old['code'] ?? '' ), 'fields' => array_keys( $d ) ) );
		return self::company_out( Store::company( $new_id ) );
	}

	/** Personas con algún rol del plugin o administradoras (para asignar a empresas). */
	public static function users() {
		$users = get_users(
			array(
				'role__in' => array_merge( array_keys( Roles::ROLES ), array( 'administrator' ) ),
				'orderby'  => 'display_name',
			)
		);
		return array_map(
			fn( $u ) => array(
				'id'    => (int) $u->ID,
				'name'  => $u->display_name,
				'roles' => array_values( (array) $u->roles ),
			),
			$users
		);
	}

	/* =====================================================================
	 * Perfiles de lectura
	 * ===================================================================*/

	/** Empresa por id numérico o por código. */
	private static function company_ref( string $ref ): ?array {
		if ( ctype_digit( $ref ) ) {
			return Store::company( (int) $ref );
		}
		return Store::company_by_code( strtoupper( $ref ) );
	}

	private static function profile_out( array $c ): array {
		$p = Store::profile( $c );
		return array(
			'company_id' => $c['id'],
			'code'       => $c['code'],
			'name'       => $c['name'],
			'profile'    => $p['profile'],
			'source'     => $p['source'],
			'updated_at' => $p['updated_at'],
			'updated_by' => $p['updated_by'] ? Log::user_name( $p['updated_by'] ) : '',
		);
	}

	/** GET /profiles?company= (id o código). Sin company devuelve todos. */
	public static function profiles( \WP_REST_Request $r ) {
		return self::engine(
			function () use ( $r ) {
				$ref = self::str( $r, 'company', 20 );
				if ( '' !== $ref ) {
					$c = self::company_ref( $ref );
					return $c ? self::profile_out( $c ) : self::not_found( 'La empresa' );
				}
				return array_map( array( __CLASS__, 'profile_out' ), Store::companies() );
			},
			'No se pudieron leer los perfiles.'
		);
	}

	/** Limpia un perfil recibido: solo escalares y arreglos, textos saneados, profundidad y tamaño acotados. */
	private static function clean_profile( $v, int $depth = 0, int &$count = 0 ) {
		if ( ++$count > 50000 ) {
			throw new \LengthException( 'perfil demasiado grande' );
		}
		if ( is_array( $v ) ) {
			if ( $depth > 10 ) {
				throw new \LengthException( 'perfil demasiado profundo' );
			}
			$out = array();
			foreach ( $v as $k => $x ) {
				$key         = is_int( $k ) ? $k : mb_substr( sanitize_text_field( (string) $k ), 0, 190 );
				$out[ $key ] = self::clean_profile( $x, $depth + 1, $count );
			}
			return $out;
		}
		if ( is_string( $v ) ) {
			return mb_substr( sanitize_text_field( $v ), 0, 500 );
		}
		if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) || null === $v ) {
			return $v;
		}
		return null;
	}

	/** POST /profiles/{company} {profile:{…}} guarda; {reset:true} vuelve al predeterminado. */
	public static function profile_save( \WP_REST_Request $r ) {
		$c = self::company_ref( (string) $r->get_param( 'company' ) );
		if ( ! $c ) {
			return self::not_found( 'La empresa' );
		}
		if ( self::bool( $r, 'reset' ) ) {
			Store::save_profile( $c['id'], null, get_current_user_id() );
			Log::write( 'perfil_restablecer', 'company', $c['id'], array( 'code' => $c['code'] ) );
			return self::engine( fn() => self::profile_out( $c ), 'No se pudo leer el perfil.' );
		}
		$p = $r->get_param( 'profile' );
		if ( is_string( $p ) ) {
			$p = json_decode( $p, true );
		}
		if ( ! is_array( $p ) || ! $p ) {
			return self::err( 'Envía el perfil como objeto JSON en «profile».' );
		}
		try {
			$count = 0;
			$clean = self::clean_profile( $p, 0, $count );
		} catch ( \LengthException $e ) {
			return self::err( 'El perfil es demasiado grande o profundo.', 413 );
		}
		Store::save_profile( $c['id'], $clean, get_current_user_id() );
		Log::write( 'perfil_guardar', 'company', $c['id'], array( 'code' => $c['code'] ) );
		return self::engine( fn() => self::profile_out( $c ), 'No se pudo leer el perfil.' );
	}

	/* =====================================================================
	 * Versiones
	 * ===================================================================*/

	public static function versions() {
		return Store::versions();
	}

	public static function version_create( \WP_REST_Request $r ) {
		$key = sanitize_key( (string) $r->get_param( 'key' ) );
		if ( ! Store::valid_key( $key ) ) {
			return self::err( 'La clave solo admite minúsculas, números y guion bajo (p. ej. ppto_2028_v1).' );
		}
		if ( Store::version( $key ) ) {
			return self::err( 'Esa versión ya existe.', 409 );
		}
		$v = Store::ensure_version( $key, self::str( $r, 'label', 120 ), self::int( $r, 'year' ) );
		Log::write( 'crear', 'version', $v ? $v['id'] : 0, array( 'key' => $key ) );
		return $v;
	}

	public static function version_update( \WP_REST_Request $r ) {
		$v = Store::version( (string) $r->get_param( 'key' ) );
		if ( ! $v ) {
			return self::not_found( 'La versión' );
		}
		$status = self::str( $r, 'status', 20 );
		if ( ! in_array( $status, array( 'abierto', 'consolidado', 'cerrado' ), true ) ) {
			return self::err( 'Estado no válido: abierto, consolidado o cerrado.' );
		}
		Store::set_version_status( $v['key'], $status );
		Log::write( 'estado', 'version', $v['id'], array( 'key' => $v['key'], 'de' => $v['status'], 'a' => $status ) );
		return Store::version( $v['key'] );
	}

	/* =====================================================================
	 * Tasas mensuales
	 * ===================================================================*/

	/** {version, currencies, rows:[{currency,month,budget,avg,close,status,approved_by,approved_at}], missing[]}. */
	public static function rates( \WP_REST_Request $r ) {
		$v = self::version_param( $r );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		return array(
			'version'    => $v,
			'currencies' => Store::currencies(),
			'rows'       => Store::rates( $v['key'] ),
			'missing'    => Store::missing_rates( $v['key'], Store::companies() ),
		);
	}

	/** POST {version, rates:[{currency, month, budget?, avg, close}], approve?}. Guardar deja la tasa en borrador. */
	public static function rates_save( \WP_REST_Request $r ) {
		$v = self::version_param( $r );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$list  = $r->get_param( 'rates' );
		$saved = 0;
		foreach ( is_array( $list ) ? $list : array() as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$cur   = strtoupper( sanitize_text_field( (string) ( $it['currency'] ?? '' ) ) );
			$month = (int) ( $it['month'] ?? -1 );
			if ( ! preg_match( '/^[A-Z]{3}$/', $cur ) || 'USD' === $cur || $month < 0 || $month > 12 ) {
				return self::err( 'Cada tasa necesita moneda (3 letras, distinta de USD) y mes de 0 a 12.' );
			}
			$avg    = self::num( $it['avg'] ?? null );
			$close  = self::num( $it['close'] ?? null );
			$budget = self::num( $it['budget'] ?? null );
			if ( ( 0 !== $month && ( null === $avg || $avg <= 0 ) ) || null === $close || $close <= 0 ) {
				return self::err( 'Las tasas de ' . $cur . ' mes ' . $month . ' deben ser mayores que cero.' );
			}
			Store::save_rate( $v['key'], $cur, $month, $budget, $avg, $close, 'borrador' );
			++$saved;
		}
		$approved = 0;
		if ( self::bool( $r, 'approve' ) ) {
			$approved = Store::approve_rates( $v['key'], wp_get_current_user()->display_name );
		}
		Log::write( $approved ? 'aprobar_tasas' : 'guardar_tasas', 'version', $v['id'], array( 'key' => $v['key'], 'guardadas' => $saved, 'aprobadas' => $approved ) );
		return self::rates( self::fake_request( array( 'version' => $v['key'] ) ) );
	}

	private static function fake_request( array $params ): \WP_REST_Request {
		$q = new \WP_REST_Request( 'GET' );
		foreach ( $params as $k => $val ) {
			$q->set_param( $k, $val );
		}
		return $q;
	}

	/* =====================================================================
	 * Bitácora y demo
	 * ===================================================================*/

	public static function log( \WP_REST_Request $r ) {
		$page = max( 1, self::int( $r, 'page' ) );
		$per  = self::int( $r, 'per' ) ? self::int( $r, 'per' ) : 50;
		return Log::page( $page, $per, self::str( $r, 'object_type', 40 ) );
	}

	/** POST {users?:bool, send?:bool}: importa el corporativo y los archivos de país de la carpeta del servidor. */
	public static function demo_load( \WP_REST_Request $r ) {
		return self::engine(
			fn() => Demo::load( get_current_user_id(), self::bool( $r, 'users' ), self::bool( $r, 'send' ) ),
			'No se pudo cargar la demo. Revisa el registro del servidor.'
		);
	}

	/** POST /demo/load-one {company (código o id), version}: sube el archivo de Meridia de ese país y versión. Corporativo o dueño del país. */
	public static function demo_load_one( \WP_REST_Request $r ) {
		$ref = (string) $r->get_param( 'company' );
		$c   = ctype_digit( $ref ) ? Store::company( (int) $ref ) : Store::company_by_code( strtoupper( sanitize_key( $ref ) ) );
		if ( ! $c ) {
			return self::not_found( 'La empresa' );
		}
		if ( ! Roles::can_upload( (int) $c['id'] ) ) {
			return self::deny();
		}
		$key = sanitize_key( (string) $r->get_param( 'version' ) );
		if ( ! Store::valid_key( $key ) ) {
			return self::err( 'Indica la versión (p. ej. ppto_2027_v1).' );
		}
		$v = Store::version( $key );
		if ( $v && 'cerrado' === $v['status'] ) {
			return self::err( 'La versión está cerrada: ya no recibe archivos.', 409 );
		}
		return self::engine( fn() => Demo::load_one( $c, $key, get_current_user_id() ), 'No se pudo cargar el archivo de demo.' );
	}

	public static function demo_reset( \WP_REST_Request $r ) {
		if ( 'BORRAR' !== (string) $r->get_param( 'confirm' ) ) {
			return self::err( 'Para reiniciar escribe BORRAR en la confirmación.' );
		}
		Demo::reset();
		return array( 'ok' => true );
	}
}
