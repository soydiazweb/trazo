<?php
namespace Consolida\Rest;

use Consolida\EngineBridge;
use Consolida\EngineMissing;
use Consolida\Log;
use Consolida\Roles;
use Consolida\Schema;
use Consolida\Store;

/**
 * Carga de datos del país: subidas (lectura con el perfil del país + validación al momento), explicación de avisos,
 * aprobación y envío.
 *
 * El archivo se guarda en uploads.file_blob (no en disco: en Kinsta/nginx una carpeta de uploads no se puede proteger).
 * Para leerlo se escribe un temporal con el nombre original (el lector usa el nombre) que se borra siempre.
 */
class Data extends Base {
	const MAX_BYTES = 10485760; // 10 MB
	const ZIP_SIG   = "PK\x03\x04";

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		self::route( '/uploads', 'GET', 'uploads_list' );
		self::route( '/uploads', 'POST', 'upload_create', 'country_corp' );
		self::route( '/uploads/(?P<id>\d+)', 'GET', 'upload_get' );
		self::route( '/uploads/(?P<id>\d+)/file', 'GET', 'upload_file' );
		self::route( '/uploads/(?P<id>\d+)/approve', 'POST', 'upload_approve' );
		self::route( '/uploads/(?P<id>\d+)/send', 'POST', 'upload_send' );
		self::route( '/issues/(?P<id>\d+)/explain', 'POST', 'issue_explain' );
	}

	/* =====================================================================
	 * Archivo temporal
	 * ===================================================================*/

	/**
	 * Escribe los bytes en una carpeta temporal propia con el nombre original, llama a $fn($ruta) y borra todo,
	 * pase lo que pase.
	 */
	public static function with_temp( string $bytes, string $file_name, callable $fn ) {
		$dir  = trailingslashit( get_temp_dir() ) . 'csl-' . bin2hex( random_bytes( 12 ) );
		$name = preg_replace( '/[^A-Za-z0-9._-]+/', '_', basename( $file_name ) );
		$name = '' !== $name ? $name : 'archivo.xlsx';
		$path = $dir . '/' . $name;
		try {
			if ( ! wp_mkdir_p( $dir ) || false === file_put_contents( $path, $bytes, LOCK_EX ) ) { // phpcs:ignore
				throw new \RuntimeException( 'No se pudo escribir el temporal' );
			}
			@chmod( $path, 0600 ); // phpcs:ignore
			return $fn( $path );
		} finally {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore
			}
			if ( is_dir( $dir ) ) {
				@rmdir( $dir ); // phpcs:ignore
			}
		}
	}

	/* =====================================================================
	 * Subidas
	 * ===================================================================*/

	/** Historial de subidas de una empresa (y versión opcional). Extra al contrato, útil para «volver a subir». */
	public static function uploads_list( \WP_REST_Request $r ) {
		$company = self::company_or_deny( self::int( $r, 'company' ), 'view' );
		if ( is_wp_error( $company ) ) {
			return $company;
		}
		return Store::uploads( sanitize_key( (string) $r->get_param( 'version' ) ), $company['id'] );
	}

	/** POST multipart: file, company_id, version. */
	public static function upload_create( \WP_REST_Request $r ) {
		$company = self::company_or_deny( self::int( $r, 'company_id' ), 'upload' );
		if ( is_wp_error( $company ) ) {
			return $company;
		}
		$version = Store::version( sanitize_key( (string) $r->get_param( 'version' ) ) );
		if ( ! $version ) {
			return self::not_found( 'La versión' );
		}
		$files = $r->get_file_params();
		$f     = $files['file'] ?? null;
		if ( ! is_array( $f ) || ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			$code = is_array( $f ) ? (int) ( $f['error'] ?? 0 ) : UPLOAD_ERR_NO_FILE;
			if ( in_array( $code, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
				return self::err( 'El archivo pesa más de lo permitido (máximo 10 MB).', 413 );
			}
			return self::err( 'No llegó ningún archivo. Elige un .xlsx e inténtalo de nuevo.' );
		}
		if ( (int) $f['size'] > self::MAX_BYTES || filesize( $f['tmp_name'] ) > self::MAX_BYTES ) {
			return self::err( 'El archivo pesa más de 10 MB.', 413 );
		}
		$bytes = (string) file_get_contents( $f['tmp_name'] ); // phpcs:ignore
		return self::ingest( $company, $version, $bytes, (string) ( $f['name'] ?? 'archivo.xlsx' ), get_current_user_id() );
	}

	/**
	 * Flujo único de subida (lo usa también la demo): revisa el archivo, lo lee con el perfil del país, valida y guarda.
	 *
	 * @return array|\WP_Error {upload, issues[]}
	 */
	public static function ingest( array $company, array $version, string $bytes, string $file_name, int $uid ) {
		$file_name = mb_substr( sanitize_file_name( $file_name ), 0, 190 );
		if ( ! preg_match( '/\.xlsx$/i', $file_name ) ) {
			return self::err( 'Solo se aceptan archivos de Excel .xlsx.', 415 );
		}
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return self::err( 'El archivo pesa más de 10 MB.', 413 );
		}
		if ( strlen( $bytes ) < 4 || substr( $bytes, 0, 4 ) !== self::ZIP_SIG ) {
			return self::err( 'El archivo no es un Excel .xlsx válido.', 415 );
		}
		if ( 'cerrado' === $version['status'] ) {
			return self::err( 'La versión está cerrada: ya no recibe archivos.', 409 );
		}
		if ( ! Store::has_corporate() ) {
			return self::err( 'Primero hay que importar el archivo corporativo (países, tasas y catálogo).', 409, 'csl_no_corporate' );
		}
		$prof = self::engine( fn() => Store::profile( $company ), 'No se pudo leer el perfil del país.' );
		if ( is_wp_error( $prof ) ) {
			return $prof;
		}
		if ( ! is_array( $prof['profile'] ) ) {
			return self::err( 'No hay perfil de lectura para ' . $company['code'] . '. Configúralo en «Perfiles de lectura».', 409, 'csl_no_profile' );
		}
		$corp = Store::corporate();
		$key  = $version['key'];

		// Lectura: si falta el lector → 503; si el archivo no se deja leer → subida rechazada con FMT_READ.
		try {
			$ds = self::with_temp( $bytes, $file_name, fn( $path ) => EngineBridge::read( $path, $prof['profile'], $corp, $file_name ) );
		} catch ( EngineMissing $e ) {
			return self::err( $e->getMessage(), 503, 'csl_unavailable' );
		} catch ( \Throwable $e ) {
			error_log( '[consolida] Lector: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() ); // phpcs:ignore
			$ds = self::broken_dataset( $company, $key, $file_name );
		}
		$issues = (array) ( $ds['issues'] ?? array() );
		if ( empty( $ds['broken'] ) ) {
			$ctx  = Store::validator_ctx( $company, $key, $prof['profile'] );
			$more = self::engine( fn() => EngineBridge::validate( $ds, $ctx ), 'No se pudo validar el archivo.' );
			if ( is_wp_error( $more ) ) {
				return $more;
			}
			$issues = array_merge( $issues, (array) $more, self::mismatch_check( $ds, $company, $key ) );
		}
		$issues = self::normalize_issues( $issues, $company['code'], $key );

		$crit = 0;
		$warn = 0;
		foreach ( $issues as $i ) {
			'critico' === $i['severity'] ? ++$crit : ++$warn;
		}
		$status = $crit ? 'rechazado' : ( $warn ? 'con_avisos' : 'validado' );

		// Se guarda el Dataset con sus issues de lectura: Kpi::copied los usa (p. ej. montos CAT_UNKNOWN que el país
		// sí incluyó en su EBIT) al consolidar.
		$stored = $ds;
		$id = Store::insert_upload(
			array(
				'version'     => $key,
				'company_id'  => $company['id'],
				'file_name'   => $file_name,
				'file_blob'   => $bytes,
				'sha256'      => hash( 'sha256', $bytes ),
				'size'        => strlen( $bytes ),
				'uploaded_by' => $uid,
				'status'      => $status,
				'dataset'     => $stored,
				'criticals'   => $crit,
				'warnings'    => $warn,
			)
		);
		if ( ! $id ) {
			return self::err( 'No se pudo guardar el archivo. Si pesa varios MB, puede que la base de datos no acepte paquetes tan grandes (max_allowed_packet).', 500 );
		}
		Store::insert_issues( $id, $key, $company['id'], $issues );
		Log::write(
			'subir',
			'upload',
			$id,
			array(
				'company'   => $company['code'],
				'version'   => $key,
				'file'      => $file_name,
				'size'      => strlen( $bytes ),
				'status'    => $status,
				'criticals' => $crit,
				'warnings'  => $warn,
			)
		);
		return array(
			'upload' => self::upload_out( Store::upload( $id ) ),
			'issues' => Store::issues_for_upload( $id ),
		);
	}

	private static function broken_dataset( array $company, string $key, string $file_name ): array {
		return array(
			'broken' => true,
			'meta'   => array( 'company' => $company['code'], 'version' => $key, 'file' => $file_name ),
			'pl'     => array(),
			'bs'     => array(),
			'issues' => array(
				array(
					'code'     => 'FMT_READ',
					'severity' => 'critico',
					'category' => 'formato_escala',
					'company'  => $company['code'],
					'version'  => $key,
					'sheet'    => '',
					'message'  => 'No se pudo leer el archivo con el perfil de ' . $company['code'] . '. Revisa que sea el archivo correcto o ajusta el perfil de lectura.',
				),
			),
		);
	}

	/**
	 * El archivo dice ser de otra empresa o versión que la elegida al subir.
	 * CONTRATO: código propio de la capa WP (`UPLOAD_MISMATCH`, crítico, catalogo_periodo).
	 */
	private static function mismatch_check( array $ds, array $company, string $key ): array {
		$out = array();
		$mc  = strtoupper( (string) ( $ds['meta']['company'] ?? '' ) );
		$mv  = (string) ( $ds['meta']['version'] ?? '' );
		if ( '' !== $mc && $mc !== $company['code'] ) {
			$out[] = array(
				'code'     => 'UPLOAD_MISMATCH',
				'severity' => 'critico',
				'category' => 'catalogo_periodo',
				'sheet'    => 'portada',
				'message'  => 'El archivo es de ' . $mc . ' y se subió como ' . $company['code'] . '.',
			);
		}
		if ( '' !== $mv && Store::valid_key( $mv ) && $mv !== $key ) {
			$out[] = array(
				'code'     => 'UPLOAD_MISMATCH',
				'severity' => 'critico',
				'category' => 'catalogo_periodo',
				'sheet'    => 'portada',
				'message'  => 'El archivo es de la versión ' . Store::label_for( $mv ) . ' y se subió en ' . Store::label_for( $key ) . '.',
			);
		}
		return $out;
	}

	/** Completa campos (empresa, versión, categoría) y quita duplicados. */
	public static function normalize_issues( array $issues, string $company_code, string $key ): array {
		$seen = array();
		$out  = array();
		foreach ( $issues as $i ) {
			if ( ! is_array( $i ) || empty( $i['code'] ) ) {
				continue;
			}
			$i['company']  = strtoupper( (string) ( $i['company'] ?? $company_code ) );
			$i['version']  = (string) ( $i['version'] ?? $key );
			$i['severity'] = 'critico' === ( $i['severity'] ?? '' ) ? 'critico' : 'aviso';
			$i['category'] = isset( Store::CATEGORIES[ $i['category'] ?? '' ] ) ? $i['category'] : Store::category_for( (string) $i['code'] );
			$k             = implode( '|', array( $i['company'], $i['code'], $i['sheet'] ?? '', $i['row'] ?? '', $i['col'] ?? '', $i['month'] ?? '', $i['concept'] ?? '', $i['message'] ?? '' ) );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = $i;
		}
		return $out;
	}

	public static function upload_out( ?array $u ): ?array {
		if ( ! $u ) {
			return null;
		}
		$c                = Store::company( $u['company_id'] );
		$u['company']     = $c ? $c['code'] : '';
		$u['companyName'] = $c ? $c['name'] : '';
		return $u;
	}

	/** Subida con control de acceso. $mode: view | own | file. */
	private static function upload_or_deny( int $id, string $mode ) {
		$u = Store::upload( $id );
		if ( ! $u ) {
			return self::not_found( 'La subida' );
		}
		$ok = match ( $mode ) {
			'own'   => Roles::owns_company( $u['company_id'] ),
			'file'  => Roles::is_corp() || Roles::owns_company( $u['company_id'] ),
			default => Roles::can_view_company( $u['company_id'] ),
		};
		return $ok ? $u : self::err( 'No tienes acceso a esta subida.', 403, 'csl_forbidden' );
	}

	public static function upload_get( \WP_REST_Request $r ) {
		$u = self::upload_or_deny( self::int( $r, 'id' ), 'view' );
		if ( is_wp_error( $u ) ) {
			return $u;
		}
		$ds      = Store::upload_dataset( $u['id'] );
		$summary = null;
		if ( $ds ) {
			$rows = array();
			foreach ( array( 'pl', 'bs', 'sales', 'ic', 'ic_inventory', 'capex' ) as $k ) {
				$rows[ $k ] = count( (array) ( $ds[ $k ] ?? array() ) );
			}
			$reported = is_array( $ds['reported'] ?? null ) ? $ds['reported'] : null;
			if ( $reported ) {
				unset( $reported['rows'] );
			}
			$summary = array(
				'meta'     => $ds['meta'] ?? array(),
				'rows'     => $rows,
				'reported' => $reported,
			);
		}
		return array(
			'upload'          => self::upload_out( $u ),
			'issues'          => Store::issues_for_upload( $u['id'] ),
			'datasetSummary'  => $summary,
			'workbookSummary' => $summary,
		);
	}

	public static function upload_file( \WP_REST_Request $r ) {
		$u = self::upload_or_deny( self::int( $r, 'id' ), 'file' );
		if ( is_wp_error( $u ) ) {
			return $u;
		}
		$bytes = Store::upload_blob( $u['id'] );
		if ( null === $bytes || '' === $bytes ) {
			return self::not_found( 'El archivo' );
		}
		Log::write( 'descargar', 'upload', $u['id'], array( 'file' => $u['file_name'] ) );
		self::send_file( $bytes, $u['file_name'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		return null;
	}

	/* =====================================================================
	 * Avisos, aprobación y envío
	 * ===================================================================*/

	public static function issue_explain( \WP_REST_Request $r ) {
		$i = Store::issue( self::int( $r, 'id' ) );
		if ( ! $i ) {
			return self::not_found( 'El hallazgo' );
		}
		if ( ! Roles::owns_company( $i['company_id'] ) ) {
			return self::deny();
		}
		if ( 'aviso' !== $i['severity'] ) {
			return self::err( 'Los errores críticos no se explican: corrige el archivo y vuelve a subirlo.', 409 );
		}
		$u = $i['upload_id'] ? Store::upload( $i['upload_id'] ) : null;
		if ( $i['upload_id'] && ( ! $u || $u['superseded'] ) ) {
			return self::err( 'Este aviso es de una subida anterior.', 409 );
		}
		if ( $u && in_array( $u['status'], array( 'aprobado', 'enviado' ), true ) ) {
			return self::err( 'La subida ya fue aprobada; sube una nueva versión para cambiarla.', 409 );
		}
		$text = self::text( $r, 'explanation', 2000 );
		if ( mb_strlen( trim( $text ) ) < 5 ) {
			return self::err( 'Escribe una explicación (al menos 5 caracteres).' );
		}
		Store::update_issue( $i['id'], 'explicada', $text );
		Log::write( 'explicar', 'issue', $i['id'], array( 'code' => $i['code'], 'upload' => $i['upload_id'] ) );
		return Store::issue( $i['id'] );
	}

	/** Aprueba una subida (API y demo). */
	public static function do_approve( int $upload_id, int $uid ) {
		$u = Store::upload( $upload_id );
		if ( ! $u ) {
			return self::not_found( 'La subida' );
		}
		if ( $u['superseded'] ) {
			return self::err( 'Esta subida fue reemplazada por una más nueva.', 409 );
		}
		if ( ! in_array( $u['status'], array( 'con_avisos', 'validado' ), true ) ) {
			return self::err( 'Solo se aprueba una subida validada o con avisos (estado actual: ' . $u['status'] . ').', 409 );
		}
		$crit = 0;
		$open = 0;
		foreach ( Store::issues_for_upload( $u['id'] ) as $i ) {
			if ( 'critico' === $i['severity'] ) {
				++$crit;
			} elseif ( 'abierta' === $i['status'] ) {
				++$open;
			}
		}
		if ( $crit ) {
			return self::err( 'Hay ' . $crit . ' errores críticos: corrige el archivo y vuelve a subirlo.', 409 );
		}
		if ( $open ) {
			return self::err( 'Explica los ' . $open . ' avisos pendientes antes de aprobar.', 409 );
		}
		Store::update_upload( $u['id'], array( 'status' => 'aprobado', 'approved_by' => $uid, 'approved_at' => Schema::now() ) );
		Log::write( 'aprobar', 'upload', $u['id'], array( 'company_id' => $u['company_id'], 'version' => $u['version'] ) );
		return self::upload_out( Store::upload( $u['id'] ) );
	}

	/** Envía una subida aprobada a corporativo (API y demo). */
	public static function do_send( int $upload_id ) {
		$u = Store::upload( $upload_id );
		if ( ! $u ) {
			return self::not_found( 'La subida' );
		}
		if ( $u['superseded'] ) {
			return self::err( 'Esta subida fue reemplazada por una más nueva.', 409 );
		}
		if ( 'aprobado' !== $u['status'] ) {
			return self::err( 'Primero aprueba la subida.', 409 );
		}
		Store::update_upload( $u['id'], array( 'status' => 'enviado', 'sent_at' => Schema::now() ) );
		Log::write( 'enviar', 'upload', $u['id'], array( 'company_id' => $u['company_id'], 'version' => $u['version'] ) );
		return self::upload_out( Store::upload( $u['id'] ) );
	}

	public static function upload_approve( \WP_REST_Request $r ) {
		$u = self::upload_or_deny( self::int( $r, 'id' ), 'own' );
		if ( is_wp_error( $u ) ) {
			return $u;
		}
		if ( true !== rest_sanitize_boolean( $r->get_param( 'confirm' ) ) ) {
			return self::err( 'Confirma la aprobación (confirm: true).' );
		}
		return self::do_approve( $u['id'], get_current_user_id() );
	}

	public static function upload_send( \WP_REST_Request $r ) {
		$u = self::upload_or_deny( self::int( $r, 'id' ), 'own' );
		if ( is_wp_error( $u ) ) {
			return $u;
		}
		return self::do_send( $u['id'] );
	}
}
