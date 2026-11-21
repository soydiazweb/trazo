<?php
namespace Consolida\Rest;

use Consolida\EngineMissing;
use Consolida\Roles;
use Consolida\Store;

/**
 * Utilidades comunes de la API consolida/v1 (§15).
 * Toda ruta exige sesión iniciada + csl_access (la cookie de WordPress solo autentica si llega la cabecera X-WP-Nonce
 * con el nonce `wp_rest`). Errores: {code, message} con HTTP 4xx/5xx y mensaje en español, sin trazas de PHP.
 */
abstract class Base {
	const NS = 'consolida/v1';

	/**
	 * Registra una ruta y verifica que el método exista (una ruta sin método tumba toda la API).
	 *
	 * @param string $perm access | corp | board | corp_board | country_corp | admin
	 */
	protected static function route( string $path, string $methods, string $callback, string $perm = 'access' ): void {
		if ( ! method_exists( static::class, $callback ) ) {
			return;
		}
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( static::class, $callback ),
				'permission_callback' => array( self::class, 'perm_' . $perm ),
			)
		);
	}

	public static function perm_access() {
		if ( ! is_user_logged_in() || ! current_user_can( Roles::CAP_ACCESS ) ) {
			return new \WP_Error( 'csl_auth', 'Inicia sesión para continuar.', array( 'status' => 401 ) );
		}
		return true;
	}

	private static function need( array $caps, string $msg ) {
		$p = self::perm_access();
		if ( true !== $p ) {
			return $p;
		}
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return new \WP_Error( 'csl_forbidden', $msg, array( 'status' => 403 ) );
	}

	public static function perm_corp() {
		return self::need( array( Roles::CAP_CORP ), 'Solo el equipo corporativo puede hacer esto.' );
	}

	public static function perm_board() {
		return self::need( array( Roles::CAP_BOARD ), 'Solo la directiva o corporativo pueden ver esto.' );
	}

	public static function perm_corp_board() {
		return self::need( array( Roles::CAP_CORP, Roles::CAP_BOARD ), 'No tienes acceso a esta información.' );
	}

	public static function perm_country_corp() {
		return self::need( array( Roles::CAP_COUNTRY, Roles::CAP_CORP ), 'Tu cuenta no puede subir archivos.' );
	}

	public static function perm_admin() {
		return self::need( array( Roles::CAP_ADMIN ), 'Solo el administrador puede hacer esto.' );
	}

	/* ---------- respuestas ---------- */

	protected static function err( string $msg, int $status = 400, string $code = 'csl_error' ): \WP_Error {
		return new \WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	protected static function deny(): \WP_Error {
		return self::err( 'No tienes acceso a esta empresa.', 403, 'csl_forbidden' );
	}

	protected static function not_found( string $what = 'El registro' ): \WP_Error {
		return self::err( $what . ' no existe.', 404, 'csl_not_found' );
	}

	/**
	 * Corre código del motor sin dejar salir trazas: un error se anota en el registro de PHP del servidor y la persona
	 * recibe un mensaje en español.
	 */
	protected static function engine( callable $fn, string $msg ) {
		try {
			return $fn();
		} catch ( EngineMissing $e ) {
			return self::err( $e->getMessage(), 503, 'csl_unavailable' );
		} catch ( \Throwable $e ) {
			error_log( '[consolida] ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() ); // phpcs:ignore
			return self::err( $msg, 500, 'csl_engine' );
		}
	}

	/* ---------- parámetros ---------- */

	protected static function int( \WP_REST_Request $r, string $k ): int {
		return max( 0, (int) $r->get_param( $k ) );
	}

	protected static function str( \WP_REST_Request $r, string $k, int $max = 190 ): string {
		return mb_substr( sanitize_text_field( (string) $r->get_param( $k ) ), 0, $max );
	}

	protected static function text( \WP_REST_Request $r, string $k, int $max = 5000 ): string {
		return mb_substr( sanitize_textarea_field( (string) $r->get_param( $k ) ), 0, $max );
	}

	protected static function bool( \WP_REST_Request $r, string $k ): bool {
		return rest_sanitize_boolean( $r->get_param( $k ) ?? false );
	}

	protected static function num( $v ): ?float {
		if ( is_string( $v ) ) {
			$v = str_replace( array( ' ', ',' ), array( '', '' ), $v );
		}
		return is_numeric( $v ) && is_finite( (float) $v ) ? (float) $v : null;
	}

	protected static function date_or_null( $v ): ?string {
		$v = (string) $v;
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
	}

	/** Versión pedida en $k (clave, p. ej. ppto_2027_v1) o la versión por defecto. */
	protected static function version_param( \WP_REST_Request $r, string $k = 'version' ) {
		$key = sanitize_key( (string) $r->get_param( $k ) );
		$v   = '' !== $key ? Store::version( $key ) : Store::default_version();
		return $v ? $v : self::err( '' !== $key ? 'La versión ' . $key . ' no existe.' : 'Aún no hay versiones: importa el archivo corporativo.', 404, 'csl_not_found' );
	}

	/**
	 * Empresa con control de acceso.
	 *
	 * @param string $mode view | upload | own
	 */
	protected static function company_or_deny( int $id, string $mode = 'view' ) {
		$c = $id ? Store::company( $id ) : null;
		if ( ! $c ) {
			return self::not_found( 'La empresa' );
		}
		$ok = match ( $mode ) {
			'own'    => Roles::owns_company( $id ),
			'upload' => Roles::can_upload( $id ),
			default  => Roles::can_view_company( $id ),
		};
		return $ok ? $c : self::deny();
	}

	/** Envía un archivo binario y termina (no pasa por el JSON de la API). */
	protected static function send_file( string $bytes, string $name, string $mime ): void {
		$name = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name );
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $bytes; // phpcs:ignore -- binario del archivo.
		exit;
	}
}
